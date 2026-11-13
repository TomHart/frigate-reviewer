import { Controller } from '@hotwired/stimulus';

/*
 * Per-group bulk selection for the "similar snapshots" view: each group
 * section carries one toggle button that checks (or unchecks) every
 * snapshot checkbox in that group — bulk-classifying a whole group of
 * near-identical snapshots becomes one click instead of ticking each box.
 * The controller is scoped to its own group section, so it never touches
 * checkboxes in other groups. The button label mirrors the selection
 * state, including when individual checkboxes are ticked by hand.
 * Pure Stimulus, no external dependencies.
 */
export default class extends Controller {
    static targets = ['checkboxes', 'button'];

    static values = {
        selectLabel: { type: String, default: 'Select all' },
        deselectLabel: { type: String, default: 'Deselect all' },
    };

    connect() {
        if (!this.hasCheckboxesTarget || !this.hasButtonTarget) {
            return; // Group without a checkbox area: nothing to select.
        }

        this.changeListener = () => this.syncButton();
        this.checkboxesTarget.addEventListener('change', this.changeListener);
        this.syncButton();
    }

    disconnect() {
        if (!this.hasCheckboxesTarget || !this.changeListener) {
            return;
        }

        this.checkboxesTarget.removeEventListener('change', this.changeListener);
    }

    toggle() {
        const checkboxes = this.groupCheckboxes();
        const everyCheckboxChecked = checkboxes.every((checkbox) => checkbox.checked);

        checkboxes.forEach((checkbox) => {
            checkbox.checked = !everyCheckboxChecked;
        });

        this.syncButton();
    }

    syncButton() {
        if (!this.hasButtonTarget) {
            return;
        }

        const everyCheckboxChecked = this.groupCheckboxes().every((checkbox) => checkbox.checked);

        this.buttonTarget.textContent = everyCheckboxChecked
            ? this.deselectLabelValue
            : this.selectLabelValue;
        this.buttonTarget.setAttribute('aria-pressed', String(everyCheckboxChecked));
    }

    groupCheckboxes() {
        return Array.from(this.checkboxesTarget.querySelectorAll('input[type="checkbox"]'));
    }
}
