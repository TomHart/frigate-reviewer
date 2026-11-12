import { Controller } from '@hotwired/stimulus';

/*
 * Mirrors the domain rule "setting validity false positive clears identity"
 * (design.md D7) in the UI: choosing false positive disables and clears the
 * identity select. The server is the source of truth — the domain layer
 * enforces the same rule on every write path.
 */
export default class extends Controller {
    static targets = ['validity', 'identity'];

    connect() {
        this.apply();
    }

    onValidityChange() {
        this.apply();
    }

    apply() {
        const isFalsePositive = this.validityTarget.value === 'false_positive';

        this.identityTarget.disabled = isFalsePositive;
        if (isFalsePositive) {
            this.identityTarget.value = '';
        }
    }
}
