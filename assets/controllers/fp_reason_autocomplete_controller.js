import { Controller } from '@hotwired/stimulus';

/*
 * False-positive reason autocomplete (task 4.6, design.md D10). Fetches
 * suggestions from the server (distinct previously used reasons, ordered by
 * usage) as the reviewer types and offers them; the field stays free text, so
 * any new value can simply be typed and submitted.
 */
export default class extends Controller {
    static targets = ['input', 'list'];

    static values = {
        url: String,
        debounceMs: { type: Number, default: 200 },
    };

    connect() {
        this.debounceId = null;
        this.abortController = null;
        this.onDocumentPointerDown = (event) => {
            if (!this.element.contains(event.target)) this.hide();
        };
        document.addEventListener('pointerdown', this.onDocumentPointerDown);
    }

    disconnect() {
        clearTimeout(this.debounceId);
        this.abortController?.abort();
        document.removeEventListener('pointerdown', this.onDocumentPointerDown);
    }

    onInput() {
        clearTimeout(this.debounceId);
        this.debounceId = setTimeout(() => this.fetchSuggestions(), this.debounceMsValue);
    }

    async fetchSuggestions() {
        const query = this.inputTarget.value.trim();
        this.abortController?.abort();
        this.abortController = new AbortController();

        const url = new URL(this.urlValue, window.location.origin);
        if (query !== '') url.searchParams.set('q', query);

        try {
            const response = await fetch(url, {
                signal: this.abortController.signal,
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this.hide();
                return;
            }

            const payload = await response.json();
            this.render(payload.suggestions ?? []);
        } catch (error) {
            if (error.name !== 'AbortError') this.hide();
        }
    }

    render(suggestions) {
        const list = this.listTarget;
        list.innerHTML = '';

        if (suggestions.length === 0) {
            this.hide();
            return;
        }

        for (const suggestion of suggestions) {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = suggestion.usage > 1
                ? `${suggestion.reason} (used ${suggestion.usage}×)`
                : suggestion.reason;
            button.className = 'block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700';
            button.addEventListener('pointerdown', (event) => {
                event.preventDefault(); // Keep focus on the input.
                this.choose(suggestion.reason);
            });
            item.appendChild(button);
            list.appendChild(item);
        }

        list.hidden = false;
    }

    choose(reason) {
        this.inputTarget.value = reason;
        this.hide();
        this.inputTarget.focus();
    }

    hide() {
        this.listTarget.hidden = true;
        this.listTarget.innerHTML = '';
    }
}
