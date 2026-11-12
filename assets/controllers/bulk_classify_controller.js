import { Controller } from '@hotwired/stimulus';

/*
 * The bulk-classification form always renders the false-positive reason
 * field, but a reason only describes a false-positive classification.
 * Choosing any other validity hides, disables and clears the field so a
 * stale or stray value cannot be submitted alongside a non-false-positive
 * validity — that combination is what silently turned recorded false
 * positives into "true positive with a false-positive reason" rows,
 * emptying the dashboard's reasons breakdown.
 */
export default class extends Controller {
    static targets = ['validity', 'reasonField', 'reasonInput'];

    connect() {
        this.apply();
    }

    onValidityChange() {
        this.apply();
    }

    apply() {
        const isFalsePositive = this.validityTarget.value === 'false_positive';

        // Inline style, not the hidden attribute: the label's Tailwind
        // `flex` class would otherwise override [hidden]'s display:none.
        this.reasonFieldTarget.style.display = isFalsePositive ? '' : 'none';
        this.reasonInputTarget.disabled = !isFalsePositive;

        if (!isFalsePositive) {
            this.reasonInputTarget.value = '';
        }
    }
}
