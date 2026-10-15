/**
 * The kiosk device's endpoints (routes/kiosk.php).
 */
export const kioskRoutes = {
    show: '/kiosk',
    pair: '/kiosk/pair',
    state: '/kiosk/state',
    signIn: '/kiosk/sign-in',
    pin: '/kiosk/pin',
    punch: '/kiosk/punch',
    done: '/kiosk/done',
} as const;
