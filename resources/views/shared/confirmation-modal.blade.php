<dialog
    id="hris-modal"
    class="hris-modal"
    data-hris-modal
    aria-labelledby="hris-modal-title"
    aria-describedby="hris-modal-description"
>
    <div class="hris-modal-surface">
        <div class="hris-modal-heading">
            <span class="hris-modal-indicator" data-hris-modal-indicator aria-hidden="true"></span>
            <div>
                <span class="hris-modal-kind" data-hris-modal-kind>Confirmation</span>
                <h2 id="hris-modal-title" data-hris-modal-title>Confirm action</h2>
            </div>
        </div>
        <p id="hris-modal-description" data-hris-modal-description></p>
        <div class="hris-modal-actions">
            <button type="button" class="button button-outline" data-hris-modal-cancel>Cancel</button>
            <button type="button" class="button button-primary" data-hris-modal-confirm>Confirm</button>
        </div>
    </div>
</dialog>

<div
    id="hris-toast-region"
    class="hris-toast-region"
    role="region"
    aria-label="System notifications"
    aria-live="polite"
    aria-relevant="additions"
></div>
