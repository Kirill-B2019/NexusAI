const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

const timeoutMs = Number(meta('session-idle-timeout-seconds')) * 1000;
const remainingSeconds = Number(meta('session-idle-remaining-seconds'));
const lastActivityAtServer = Number(meta('session-idle-last-activity'));
const serverNow = Number(meta('session-idle-server-now'));
const activityUrl = meta('session-activity-url');
const expireUrl = meta('session-expire-url');
const homeUrl = meta('session-home-url');
const csrfToken = meta('csrf-token');
const userId = meta('session-user-id');

if (
    Number.isFinite(timeoutMs) &&
    timeoutMs > 0 &&
    Number.isFinite(remainingSeconds) &&
    Number.isFinite(lastActivityAtServer) &&
    Number.isFinite(serverNow) &&
    activityUrl &&
    expireUrl &&
    homeUrl &&
    csrfToken &&
    userId
) {
    const storageKey = `nexus:last-user-activity:${userId}`;
    const serverClockOffset = serverNow * 1000 - Date.now();
    const channel = 'BroadcastChannel' in window
        ? new BroadcastChannel(`nexus-session:${userId}`)
        : null;

    let lastActivityWallAt = Date.now() - Math.max(0, timeoutMs - remainingSeconds * 1000);
    let idleTimer;
    let activityTimer;
    let maxActivityTimer;
    let syncInFlight = false;
    let expired = false;
    let lastBroadcastAt = 0;
    let lastStorageWriteAt = 0;
    let activityVersion = 0;
    let lastIdleScheduleAt = 0;
    let lastActivityPingAt = performance.now() - Math.max(0, timeoutMs - remainingSeconds * 1000);
    let lastActivityPingAttemptAt = lastActivityPingAt;

    const syncFromStoredActivity = () => {
        try {
            const storedAt = Number(localStorage.getItem(storageKey));
            const storedAtServer = storedAt + serverClockOffset;
            const estimatedServerNow = Date.now() + serverClockOffset;

            if (
                Number.isFinite(storedAt) &&
                storedAtServer >= lastActivityAtServer * 1000 &&
                storedAtServer <= estimatedServerNow + 5000
            ) {
                const age = Math.max(0, Date.now() - storedAt);

                if (age < timeoutMs) {
                    lastActivityWallAt = storedAt;
                }
            }
        } catch (error) {
            console.warn('Не удалось синхронизировать активность между вкладками.', error);
        }
    };

    const navigateHome = () => {
        window.location.replace(homeUrl);
    };

    const expireSession = async (notifyTabs = true) => {
        if (expired) {
            return;
        }

        expired = true;
        window.clearTimeout(idleTimer);
        window.clearTimeout(activityTimer);
        window.clearTimeout(maxActivityTimer);

        let timeoutId;
        try {
            const timeout = new Promise((_, reject) => {
                timeoutId = window.setTimeout(() => reject(new Error('Logout request timed out.')), 3000);
            });
            const request = fetch(expireUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    Accept: 'text/html',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({ _token: csrfToken }),
            });
            const response = await Promise.race([request, timeout]);

            if (!response.ok && !response.redirected) {
                throw new Error(`Logout endpoint returned HTTP ${response.status}.`);
            }
        } catch (error) {
            console.error('Не удалось подтвердить выход на сервере; переход на главную страницу.', error);
        } finally {
            window.clearTimeout(timeoutId);
            if (notifyTabs) {
                channel?.postMessage({ type: 'expired' });
            }
            navigateHome();
        }
    };

    const checkDeadline = () => {
        if (expired) {
            return true;
        }

        if (Date.now() - lastActivityWallAt >= timeoutMs - 1000) {
            syncFromStoredActivity();
        }

        if (Date.now() - lastActivityWallAt >= timeoutMs) {
            void expireSession();
            return true;
        }

        return false;
    };

    const scheduleIdleCheck = () => {
        window.clearTimeout(idleTimer);
        lastIdleScheduleAt = performance.now();

        const remainingMs = Math.max(0, timeoutMs - (Date.now() - lastActivityWallAt));
        idleTimer = window.setTimeout(() => {
            if (document.visibilityState === 'visible') {
                checkDeadline();
                if (!expired) {
                    scheduleIdleCheck();
                }
            }
        }, remainingMs);
    };

    const sendActivity = async () => {
        window.clearTimeout(activityTimer);
        window.clearTimeout(maxActivityTimer);
        activityTimer = undefined;
        maxActivityTimer = undefined;

        const now = performance.now();
        if (expired || syncInFlight || now - lastActivityPingAttemptAt < 1000) {
            return;
        }

        lastActivityPingAttemptAt = now;
        syncInFlight = true;
        const versionAtRequest = activityVersion;

        try {
            const response = await fetch(activityUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({ _token: csrfToken }),
            });

            if (response.redirected || response.status === 401) {
                expired = true;
                navigateHome();
            } else if (!response.ok) {
                throw new Error(`Activity endpoint returned HTTP ${response.status}.`);
            } else {
                lastActivityPingAt = performance.now();
            }
        } catch (error) {
            console.error('Не удалось синхронизировать активность с сервером.', error);
        } finally {
            syncInFlight = false;

            if (
                !expired &&
                activityVersion > versionAtRequest &&
                activityTimer === undefined &&
                maxActivityTimer === undefined
            ) {
                activityTimer = window.setTimeout(sendActivity, 0);
            }
        }
    };

    const recordActivity = () => {
        const now = performance.now();
        activityVersion++;
        lastActivityWallAt = Date.now();

        if (now - lastIdleScheduleAt >= 250) {
            scheduleIdleCheck();
        }

        if (Date.now() - lastStorageWriteAt >= 1000) {
            try {
                localStorage.setItem(storageKey, String(Date.now()));
                lastStorageWriteAt = Date.now();
            } catch (error) {
                console.warn('Не удалось сохранить активность для синхронизации вкладок.', error);
            }
        }

        if (now - lastBroadcastAt >= 500) {
            channel?.postMessage({ type: 'activity' });
            lastBroadcastAt = now;
        }

        window.clearTimeout(activityTimer);
        activityTimer = window.setTimeout(sendActivity, 1000);
        if (maxActivityTimer === undefined) {
            maxActivityTimer = window.setTimeout(sendActivity, 30000);
        }
    };

    const acceptRemoteActivity = () => {
        lastActivityWallAt = Date.now();
        scheduleIdleCheck();
    };

    const onUserActivity = (event) => {
        if (!event.isTrusted || document.visibilityState !== 'visible' || checkDeadline()) {
            return;
        }

        recordActivity();

        if (performance.now() - lastActivityPingAt >= 5000) {
            void sendActivity();
        }
    };

    const activityEvents = ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart', 'input'];
    activityEvents.forEach((eventName) => {
        window.addEventListener(eventName, onUserActivity, { passive: true });
    });

    channel?.addEventListener('message', (event) => {
        if (event.data?.type === 'activity') {
            acceptRemoteActivity();
        } else if (event.data?.type === 'expired') {
            expired = true;
            navigateHome();
        }
    });

    window.addEventListener('storage', (event) => {
        if (event.key === storageKey && event.newValue) {
            syncFromStoredActivity();
            scheduleIdleCheck();
        }
    });

    document.addEventListener('visibilitychange', () => {
        syncFromStoredActivity();
        if (document.visibilityState === 'visible' && !checkDeadline()) {
            scheduleIdleCheck();
        }
    });
    window.addEventListener('pageshow', () => {
        syncFromStoredActivity();
        if (!checkDeadline()) {
            scheduleIdleCheck();
        }
    });
    window.addEventListener('focus', () => {
        syncFromStoredActivity();
        if (!checkDeadline()) {
            scheduleIdleCheck();
        }
    });

    syncFromStoredActivity();
    scheduleIdleCheck();
}
