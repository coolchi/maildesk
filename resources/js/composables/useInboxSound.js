/**
 * Soft UI notification chime via Web Audio (no media asset required).
 * Preference defaults to on; sync from shared auth props via setInboxSoundPreference.
 */

let sharedContext = null;
let preferenceEnabled = true;
let unlockPromise = null;

export function setInboxSoundPreference(enabled) {
    preferenceEnabled = enabled !== false;
}

function getAudioContext() {
    if (typeof window === 'undefined') {
        return null;
    }

    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) {
        return null;
    }

    if (!sharedContext) {
        sharedContext = new AudioCtx();
    }

    return sharedContext;
}

async function ensureRunningContext() {
    const ctx = getAudioContext();
    if (!ctx) {
        return null;
    }

    if (ctx.state === 'suspended') {
        try {
            await ctx.resume();
        } catch {
            return null;
        }
    }

    return ctx.state === 'running' ? ctx : null;
}

/** Call once after a user gesture so later chimes are allowed. */
export function unlockInboxAudio() {
    if (unlockPromise) {
        return unlockPromise;
    }

    unlockPromise = (async () => {
        const ctx = await ensureRunningContext();
        if (!ctx) {
            unlockPromise = null;
            return;
        }

        try {
            const buffer = ctx.createBuffer(1, 1, 22050);
            const source = ctx.createBufferSource();
            source.buffer = buffer;
            source.connect(ctx.destination);
            source.start(0);
        } catch {
            // ignore
        }
    })();

    return unlockPromise;
}

/**
 * Soft rising “liquid” notification — two partials with a gentle sparkle.
 *
 * @param {{ force?: boolean }} [options]
 */
export async function playInboxSound(options = {}) {
    if (typeof window === 'undefined') {
        return;
    }

    if (!options.force && !preferenceEnabled) {
        return;
    }

    try {
        const ctx = await ensureRunningContext();
        if (!ctx) {
            return;
        }

        const now = ctx.currentTime;
        const master = ctx.createGain();
        master.gain.setValueAtTime(0.0001, now);
        master.gain.exponentialRampToValueAtTime(0.28, now + 0.03);
        master.gain.exponentialRampToValueAtTime(0.0001, now + 0.85);
        master.connect(ctx.destination);

        // Warm low-pass so it feels soft, not piercing.
        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.setValueAtTime(2400, now);
        filter.frequency.exponentialRampToValueAtTime(3200, now + 0.12);
        filter.Q.setValueAtTime(0.7, now);
        filter.connect(master);

        /**
         * @param {number} freq
         * @param {number} start
         * @param {number} dur
         * @param {number} peak
         * @param {OscillatorType} type
         */
        const tone = (freq, start, dur, peak, type = 'sine') => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, now + start);
            // Slight downward glide for a liquid feel.
            osc.frequency.exponentialRampToValueAtTime(
                freq * 0.985,
                now + start + dur,
            );
            gain.gain.setValueAtTime(0.0001, now + start);
            gain.gain.exponentialRampToValueAtTime(peak, now + start + 0.025);
            gain.gain.exponentialRampToValueAtTime(
                0.0001,
                now + start + dur,
            );
            osc.connect(gain);
            gain.connect(filter);
            osc.start(now + start);
            osc.stop(now + start + dur + 0.05);
        };

        // Major triad arpeggio: A5 → C#6 → E6 (cool, app-like “got mail”).
        tone(880.0, 0.0, 0.28, 0.55, 'sine');
        tone(1108.73, 0.09, 0.32, 0.48, 'sine');
        tone(1318.51, 0.18, 0.42, 0.4, 'triangle');

        // Quiet harmonic shimmer on the last note.
        tone(2637.02, 0.2, 0.35, 0.08, 'sine');
    } catch {
        // Autoplay policies / missing AudioContext — fail quietly.
    }
}
