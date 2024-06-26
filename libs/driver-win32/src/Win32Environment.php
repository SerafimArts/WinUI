<?php

declare(strict_types=1);

namespace Local\Driver\Win32;

use FFI\CData;
use Local\Driver\Win32\Handle\Win32ClassHandleFactory;
use Local\Driver\Win32\Handle\Win32InstanceHandle;
use Local\Driver\Win32\Handle\Win32WindowHandleFactory;
use Local\Driver\Win32\Lib\Kernel32;
use Local\Driver\Win32\Lib\Ole32;
use Local\Driver\Win32\Lib\User32;
use Local\WebView2\InstallationDetector;
use Local\WebView2\WebView2;
use Serafim\Boson\ApplicationInterface;
use Serafim\Boson\Event\Application\AppStartedEvent;
use Serafim\Boson\Event\Application\AppStartingHook;
use Serafim\Boson\Event\Application\AppStoppedEvent;
use Serafim\Boson\Event\Application\AppStoppingHook;
use Serafim\Boson\Window\CreateInfo;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @internal This is an internal library class, please do not use it in your code.
 */
final class Win32Environment implements ApplicationInterface
{
    /**
     * Initializes the thread for apartment-threaded object concurrency.
     *
     * @link https://learn.microsoft.com/en-us/windows/win32/api/objbase/ne-objbase-coinit
     */
    private const int COINIT_APARTMENTTHREADED = 0x02;

    private bool $isRunning = false;

    private readonly Ole32 $ole32;
    private readonly User32 $user32;
    private readonly Kernel32 $kernel32;
    private readonly InstallationDetector $installation;
    private readonly Win32InstanceHandle $instance;

    private readonly Win32ClassHandleFactory $classes;

    private readonly CData $message;

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly string $bootstrap,
    ) {
        $this->ole32 = new Ole32();
        $this->user32 = new User32();
        $this->kernel32 = new Kernel32();
        $this->classes = new Win32ClassHandleFactory(
            events: $this->events,
            user32: $this->user32,
        );

        $this->installation = new InstallationDetector();
        $this->instance = $this->createInstanceHandle();
        $this->message = $this->createMessage();

        $this->ole32->CoInitializeEx(null, self::COINIT_APARTMENTTHREADED);
    }

    private function createMessage(): CData
    {
        // @phpstan-ignore-next-line
        return \FFI::addr($this->user32->new('MSG'));
    }

    private function createInstanceHandle(): Win32InstanceHandle
    {
        return new Win32InstanceHandle(
            ptr: $this->kernel32->GetModuleHandleA(null),
        );
    }

    public function create(CreateInfo $info = new CreateInfo()): Win32Window
    {
        $this->installation->assertIsInstalledOrFail();

        $result = new Win32Window(
            app: $this,
            info: $info,
            bootstrap: $this->bootstrap,
            events: $this->events,
            instance: $this->instance,
            classes: $this->classes,
            windows: new Win32WindowHandleFactory(
                user32: $this->user32,
            ),
            user32: $this->user32,
            webView2: new WebView2(),
        );

        $this->classes->attach($result);

        return $result;
    }

    /**
     * Dispatch staring hooks and events.
     *
     * @return bool Return {@see true} in case of application should start
     *         or {@see false} if it should not.
     */
    private function dispatchStarting(): bool
    {
        $hook = $this->events->dispatch(new AppStartingHook($this));

        if ($hook->isPropagationStopped()) {
            return false;
        }

        $this->events->dispatch(new AppStartedEvent($this));

        return true;
    }

    public function run(): void
    {
        if ($this->isRunning === true) {
            return;
        }

        $this->isRunning = $this->dispatchStarting();

        // @phpstan-ignore-next-line
        while ($this->isRunning) {
            if ($this->user32->GetMessageW($this->message, null, 0, 0)) {
                $this->user32->TranslateMessage($this->message);
                $this->user32->DispatchMessageW($this->message);
            }

            \usleep(1);
        }
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Dispatch stop hooks and events.
     *
     * @return bool Return {@see true} in case of application should stop
     *         or {@see false} if it should not.
     */
    private function dispatchStopping(): bool
    {
        $hook = $this->events->dispatch(new AppStoppingHook($this));

        if ($hook->isPropagationStopped()) {
            return false;
        }

        $this->events->dispatch(new AppStoppedEvent($this));

        return true;
    }

    public function stop(): void
    {
        if ($this->isRunning === false) {
            return;
        }

        $this->isRunning = ! $this->dispatchStopping();
    }

    public function __destruct()
    {
        $this->ole32->CoUninitialize();
    }
}
