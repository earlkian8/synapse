/**
 * The kiosk's beep — the sound a bundy clock makes, so a punch can be heard to
 * land across a noisy floor. A short tone for a punch, two low ones for a
 * refusal. The browser starts audio only after a tap, which a punch always is.
 */
let context: AudioContext | null = null;

function tone(frequency: number, start: number, duration: number): void {
    if (!context) {
        return;
    }

    const oscillator = context.createOscillator();
    const gain = context.createGain();
    const at = context.currentTime + start;

    oscillator.type = 'sine';
    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(0.18, at + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);

    oscillator.connect(gain).connect(context.destination);
    oscillator.start(at);
    oscillator.stop(at + duration + 0.02);
}

export function beep(kind: 'punch' | 'refused'): void {
    try {
        context ??= new AudioContext();
        void context.resume();
    } catch {
        return;
    }

    if (kind === 'punch') {
        tone(1320, 0, 0.16);
    } else {
        tone(330, 0, 0.12);
        tone(262, 0.16, 0.18);
    }
}
