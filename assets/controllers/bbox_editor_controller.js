import { Controller } from '@hotwired/stimulus';

const FIELDS = ['x', 'y', 'width', 'height'];

/*
 * SVG bounding-box editor (task 4.3). Renders the snapshot thumbnail with an
 * absolutely-positioned SVG overlay; the overlay's viewBox is "0 0 1 1" with
 * preserveAspectRatio="none", so its user coordinates ARE the normalized
 * 0..1 BoundingBox coordinates — no scaling math beyond the pointer mapping.
 *
 * The original Frigate box (dashed grey) is display-only; the reviewer draws,
 * moves and resizes the corrected box (solid green). The current corrected
 * box is mirrored into hidden inputs (box_x, box_y, box_width, box_height,
 * box_clear) that the review / manual-object forms submit.
 *
 * Interaction:
 * - drag on empty space  -> draw a new corrected box (replaces the old one)
 * - drag inside the box  -> move it
 * - drag a corner handle -> resize it (opposite corner stays fixed)
 */
export default class extends Controller {
    static targets = ['svg', 'originalRect', 'correctedRect', 'input', 'clearInput', 'clearButton'];

    static values = {
        originalBox: String,
        correctedBox: String,
        minSize: { type: Number, default: 0.02 },
        handleSizePx: { type: Number, default: 12 },
    };

    connect() {
        this.originalBox = this.parseBox(this.originalBoxValue);
        this.correctedBox = this.parseBox(this.correctedBoxValue);
        this.cleared = false;
        this.drag = null;

        this.onPointerMove = this.onPointerMove.bind(this);
        this.onPointerUp = this.onPointerUp.bind(this);
        this.onPointerCancel = this.onPointerCancel.bind(this);
        this.svgTarget.addEventListener('pointerdown', (event) => this.onPointerDown(event));
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        window.addEventListener('pointercancel', this.onPointerCancel);

        this.render();
    }

    disconnect() {
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        window.removeEventListener('pointercancel', this.onPointerCancel);
    }

    clear() {
        this.correctedBox = null;
        this.cleared = true;
        this.render();
    }

    onPointerDown(event) {
        event.preventDefault();
        const point = this.pointFromEvent(event);
        const box = this.correctedBox;

        const corner = box === null ? null : this.cornerNear(event, box);
        if (corner) {
            this.drag = { mode: 'resize', corner, fixed: this.oppositeCorner(box, corner) };
        } else if (box !== null && this.insideBox(point, box)) {
            this.drag = { mode: 'move', offset: { x: point.x - box.x, y: point.y - box.y } };
        } else {
            this.drag = { mode: 'draw', origin: point, previous: this.correctedBox };
            this.correctedBox = { x: point.x, y: point.y, width: 0, height: 0 };
            this.cleared = false;
        }

        this.render();
    }

    onPointerMove(event) {
        if (this.drag === null) return;
        const point = this.pointFromEvent(event);

        if (this.drag.mode === 'draw') {
            this.correctedBox = this.normalizeBox(this.drag.origin, point);
        } else if (this.drag.mode === 'move') {
            const box = this.correctedBox;
            const x = clamp(point.x - this.drag.offset.x, 0, 1 - box.width);
            const y = clamp(point.y - this.drag.offset.y, 0, 1 - box.height);
            this.correctedBox = { ...box, x, y };
        } else {
            this.correctedBox = this.normalizeBox(this.drag.fixed, point);
        }

        this.render();
    }

    onPointerUp() {
        if (this.drag === null) return;

        if (this.drag.mode === 'draw' && this.isTooSmall(this.correctedBox)) {
            // An accidental click must not wipe out the previous box.
            this.correctedBox = this.drag.previous;
        }

        this.drag = null;
        this.render();
    }

    onPointerCancel() {
        if (this.drag === null) return;

        // Touch scrolling or palm rejection can cancel a drag mid-stroke
        // without a pointerup: restore the pre-drag state so the transient
        // 0x0 draw box never leaks into the hidden inputs.
        if (this.drag.mode === 'draw') {
            this.correctedBox = this.drag.previous;
        }

        this.drag = null;
        this.render();
    }

    render() {
        this.applyRect(this.originalRectTarget, this.originalBox);
        this.applyRect(this.correctedRectTarget, this.correctedBox);

        for (const input of this.inputTargets) {
            // No box in editor state -> hidden inputs stay EMPTY so the form
            // submits "box not provided" and any stored box is left untouched.
            // Never write zero-placeholders: the server would parse them as an
            // invalid all-zero box ("width and height must be positive").
            input.value = this.cleared || this.correctedBox === null
                ? ''
                : this.correctedBox[input.dataset.field].toFixed(4);
        }

        this.clearInputTarget.value = this.cleared ? '1' : '';

        // The clear button only means something while a box exists in the
        // editor state (including the corrected box loaded from the review).
        this.clearButtonTarget.disabled = this.correctedBox === null;
    }

    applyRect(rect, box) {
        if (box === null || box.width === 0 || box.height === 0) {
            rect.setAttribute('visibility', 'hidden');
            return;
        }

        rect.removeAttribute('visibility');
        rect.setAttribute('x', box.x);
        rect.setAttribute('y', box.y);
        rect.setAttribute('width', box.width);
        rect.setAttribute('height', box.height);
    }

    // -- geometry helpers ---------------------------------------------------

    pointFromEvent(event) {
        const rect = this.svgTarget.getBoundingClientRect();

        return {
            x: clamp((event.clientX - rect.left) / rect.width, 0, 1),
            y: clamp((event.clientY - rect.top) / rect.height, 0, 1),
        };
    }

    cornerNear(event, box) {
        const rect = this.svgTarget.getBoundingClientRect();
        const tolerance = this.handleSizePxValue;

        for (const corner of ['nw', 'ne', 'sw', 'se']) {
            const point = this.cornerPoint(box, corner);
            const pixelX = point.x * rect.width;
            const pixelY = point.y * rect.height;
            const offsetX = event.clientX - rect.left;
            const offsetY = event.clientY - rect.top;

            if (Math.abs(offsetX - pixelX) <= tolerance && Math.abs(offsetY - pixelY) <= tolerance) {
                return corner;
            }
        }

        return null;
    }

    cornerPoint(box, corner) {
        const x = corner.includes('w') ? box.x : box.x + box.width;
        const y = corner.includes('n') ? box.y : box.y + box.height;
        return { x, y };
    }

    oppositeCorner(box, corner) {
        return {
            x: corner.includes('w') ? box.x + box.width : box.x,
            y: corner.includes('n') ? box.y + box.height : box.y,
        };
    }

    /** A rectangle from two opposite corners, ordered and clamped to 0..1. */
    normalizeBox(first, second) {
        return {
            x: Math.min(first.x, second.x),
            y: Math.min(first.y, second.y),
            width: Math.abs(second.x - first.x),
            height: Math.abs(second.y - first.y),
        };
    }

    insideBox(point, box) {
        return point.x >= box.x && point.x <= box.x + box.width
            && point.y >= box.y && point.y <= box.y + box.height;
    }

    isTooSmall(box) {
        return box === null || box.width < this.minSizeValue || box.height < this.minSizeValue;
    }

    parseBox(value) {
        if (typeof value !== 'string' || value.trim() === '') return null;
        const [x, y, width, height] = value.split(',').map(Number);
        if ([x, y, width, height].some((n) => !Number.isFinite(n))) return null;
        return { x, y, width, height };
    }
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}
