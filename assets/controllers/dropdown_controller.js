import { Controller } from '@hotwired/stimulus';

/*
 * Split-button dropdown on detection cards: the visible action is the Review
 * link, the caret opens a menu of one-click identity classifications.
 * Closes on outside click and Escape so speed-reviewing never leaves a
 * stray menu open. Pure Stimulus, no external dependencies.
 */
export default class extends Controller {
    static targets = ['toggle', 'menu'];

    connect() {
        if (!this.hasMenuTarget) {
            return; // Review-only card: no menu to manage.
        }

        this.outsideClickListener = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };

        this.escapeListener = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };

        document.addEventListener('click', this.outsideClickListener);
        document.addEventListener('keydown', this.escapeListener);
    }

    disconnect() {
        if (!this.hasMenuTarget) {
            return;
        }

        document.removeEventListener('click', this.outsideClickListener);
        document.removeEventListener('keydown', this.escapeListener);
    }

    toggle(event) {
        event.stopPropagation();
        this.menuTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.menuTarget.hidden = false;
        this.toggleTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        if (!this.hasMenuTarget) {
            return;
        }

        this.menuTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
    }
}
