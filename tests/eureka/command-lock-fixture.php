<?php
declare(strict_types=1);

// Prozessweiter Symcon-Nachbau, geteilt von unabhängigen Befehlsobjekten.
$GLOBALS['commandLocks'] = [];
$GLOBALS['commandLockTimeout'] = false;
function IPS_SemaphoreEnter(string $name, int $timeout): bool
{
    commandAssert($timeout === 1);
    if ($GLOBALS['commandLockTimeout'] || isset($GLOBALS['commandLocks'][$name])) { return false; }
    $GLOBALS['commandLocks'][$name] = true;
    if (isset($GLOBALS['commandAfterLock'])) {
        $action = $GLOBALS['commandAfterLock'];
        unset($GLOBALS['commandAfterLock']);
        $action();
    }
    return true;
}
function IPS_SemaphoreLeave(string $name): void
{
    commandAssert(isset($GLOBALS['commandLocks'][$name]));
    unset($GLOBALS['commandLocks'][$name]);
}
