<?php

declare(strict_types=1);

namespace Local\Driver\Win32\Handle;

use FFI\CData;
use Local\Com\WideString;
use Local\Driver\Win32\Lib\Color;
use Local\Driver\Win32\Lib\Cursor;
use Local\Driver\Win32\Lib\Icon;
use Local\Driver\Win32\Lib\User32;
use Local\Driver\Win32\Lib\WindowClassStyle;
use Local\Driver\Win32\Lib\WindowMessage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Serafim\Boson\Event\Window\WindowFocusLostEvent;
use Serafim\Boson\Event\Window\WindowClosedEvent;
use Serafim\Boson\Event\Window\WindowFocusReceivedEvent;
use Serafim\Boson\Event\Window\WindowHiddenEvent;
use Serafim\Boson\Event\Window\WindowMovedEvent;
use Serafim\Boson\Event\Window\WindowResizeEvent;
use Serafim\Boson\Event\Window\WindowShownEvent;
use Serafim\Boson\Exception\WindowNotCreatableException;
use Serafim\Boson\Memory\MemorySet;
use Serafim\Boson\Window\CreateInfo;
use Serafim\Boson\WindowInterface;

/**
 * @internal this is an internal library class, please do not use it in your code
 */
final readonly class Win32ClassHandleFactory
{
    /**
     * @var int-mask-of<WindowClassStyle::CS_*>
     */
    private const int DEFAULT_CLASS_STYLE = WindowClassStyle::CS_HREDRAW
        | WindowClassStyle::CS_VREDRAW
        | WindowClassStyle::CS_OWNDC;

    /**
     * @var MemorySet<Win32ClassHandle>
     */
    private MemorySet $handles;

    public function __construct(
        private EventDispatcherInterface $events,
        private User32 $user32,
    ) {
        $this->handles = new MemorySet();
    }

    private function createWindowClass(CreateInfo $info, Win32InstanceHandle $module): CData
    {
        $class = $this->user32->new('WNDCLASSW');

        if ($class === null) {
            throw new \RuntimeException('Could not create window class structure');
        }

        $class->hCursor = $this->user32->LoadCursorW(null, Cursor::IDC_ARROW->toCData());
        $class->cbClsExtra = 0;
        $class->cbWndExtra = 0;
        $class->style = self::DEFAULT_CLASS_STYLE;
        $class->hIcon = $this->user32->LoadIconW(null, Icon::IDI_APPLICATION->toCData());
        $class->hInstance = $module->ptr;
        $class->lpszMenuName = null;
        $class->lpszClassName = WideString::toWideStringCData($this->user32, __CLASS__);
        $class->hbrBackground = $this->user32->GetSysColorBrush(Color::COLOR_WINDOW);

        if ($info->closable === false) {
            $class->style = WindowClassStyle::CS_NOCLOSE;
        }

        /** @var CData */
        return $class;
    }

    private function registerWindowClass(CData $info): CData
    {
        if (!$this->user32->RegisterClassW(\FFI::addr($info))) {
            throw new WindowNotCreatableException('Could not initialize window class');
        }

        return $info;
    }

    private function registerWindowFunction(CData $info, WindowInterface $window): CData
    {
        // @phpstan-ignore-next-line
        $info->lpfnWndProc = function (CData $hWnd, int $msg, int $wParam, int $lParam) use ($window): int {
            switch ($msg) {
                case WindowMessage::WM_CLOSE:
                    $this->events->dispatch(new WindowClosedEvent($window));
                    return 0;

                case WindowMessage::WM_SETFOCUS:
                    $this->events->dispatch(new WindowFocusReceivedEvent($window));
                    return 0;

                case WindowMessage::WM_KILLFOCUS:
                    $this->events->dispatch(new WindowFocusLostEvent($window));
                    return 0;

                case WindowMessage::WM_MOVE:
                    $this->events->dispatch(new WindowMovedEvent(
                        $window,
                        self::loWord($lParam),
                        self::hiWord($lParam),
                    ));
                    return 0;

                case WindowMessage::WM_ACTIVATE:
                    if ($wParam !== 1) {
                        $this->events->dispatch(new WindowHiddenEvent($window));
                    } else {
                        $this->events->dispatch(new WindowShownEvent($window));
                    }
                    return 0;

                case WindowMessage::WM_CAPTURECHANGED:
                    // change full size/normal states
                    //echo sprintf("[%08d] %s\n", $msg, WindowMessage::get($msg));
                    return 0;

                case WindowMessage::WM_SIZE:
                    $this->events->dispatch(new WindowResizeEvent(
                        $window,
                        \max(0, self::loWord($lParam)),
                        \max(0, self::hiWord($lParam)),
                    ));
                    return 0;
            }

            return $this->user32->DefWindowProcW($hWnd, $msg, $wParam, $lParam);
        };

        return $info;
    }

    private static function loWord(int $value): int
    {
        return ($value &= 0xffff) > 32767 ? $value - 65535 : $value;
    }

    /**
     * ```
     *  (unsigned short)((unsigned long)value >> 16) & 0xffff
     * ```
     */
    private static function hiWord(int $value): int
    {
        $value = ($value >> 16) & 0xffff;

        return $value > 32767 ? $value - 65535 : $value;
    }

    public function create(CreateInfo $info, Win32InstanceHandle $instance, WindowInterface $window): Win32ClassHandle
    {
        $result = new Win32ClassHandle(
            instance: $instance,
            id: __CLASS__,
            ptr: $this->registerWindowClass(
                info: $this->registerWindowFunction(
                    info: $this->createWindowClass(
                        info: $info,
                        module: $instance,
                    ),
                    window: $window,
                ),
            ),
        );

        $this->handles->create($result, function (Win32ClassHandle $handle) {
            $this->user32->UnregisterClassW(
                $handle->id,
                null,
            );
        });

        return $result;
    }
}
