/**
 * Lifts the app's toaster clear of the wizard's own footer.
 *
 * Toasts land bottom-right, which everywhere else in the app is empty space. The
 * wizard is the one surface with a pinned action bar there, so a step's
 * confirmation would otherwise cover **Save and continue** — and, on the send-off
 * screen, **Finish and go to dashboard** — for as long as it is showing.
 *
 * The app's single `<Toaster>` is mounted globally in `app.tsx`, so a page cannot
 * pass it props; it reads these two variables instead (see `components/ui/sonner.tsx`,
 * where their fallbacks are sonner's own defaults). The style unmounts with the
 * page, so nothing else in the app moves.
 *
 * The two values differ because the footer does: on a phone it stacks the note
 * under the buttons.
 */
export default function ToastClearance() {
    return (
        <style>{`
            :root {
                --app-toast-offset-bottom: 5.75rem;
                --app-toast-offset-bottom-mobile: 7.5rem;
            }
        `}</style>
    );
}
