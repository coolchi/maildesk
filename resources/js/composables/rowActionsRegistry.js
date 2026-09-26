/**
 * App-wide registry so only one RowActions menu is open at a time.
 * Must live outside the SFC — <script setup> vars are per-instance.
 */
const closers = new Map();

let activeId = null;
let seq = 0;

export function registerRowActions(close) {
    const id = ++seq;
    closers.set(id, close);
    return id;
}

export function unregisterRowActions(id) {
    closers.delete(id);
    if (activeId === id) {
        activeId = null;
    }
}

export function claimRowActions(id) {
    closers.forEach((close, otherId) => {
        if (otherId !== id) {
            close();
        }
    });
    activeId = id;
}

export function releaseRowActions(id) {
    if (activeId === id) {
        activeId = null;
    }
}
