import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import DocumentService from "@typo3/core/document-service.js";
import Icons from "@typo3/backend/icons.js";
import Notification from "@typo3/backend/notification.js";
import RegularEvent from "@typo3/core/event/regular-event.js";

const routeMap = {
    "reset-missing": "file_sync_reset_missing",
    "delete-files": "file_sync_delete_files",
};

class StorageActions {
    constructor() {
        DocumentService.ready().then(() => this.registerEvents());
    }

    registerEvents() {
        new RegularEvent("click", (event, target) => {
            event.preventDefault();
            this.handleAction(target);
        }).delegateTo(document, ".t3js-file-sync-action");
    }

    async handleAction(target) {
        const action = target.dataset.action;
        const routeName = routeMap[action];
        if (!routeName) {
            return;
        }

        target.disabled = true;
        target.classList.add("disabled");

        const iconElement = target.querySelector(".t3js-icon");
        if (iconElement) {
            const spinnerMarkup = await Icons.getIcon("spinner-circle", Icons.sizes.small);
            iconElement.replaceWith(document.createRange().createContextualFragment(spinnerMarkup));
        }

        const postData = { storageUid: target.dataset.storageUid };
        if (target.dataset.identifier) {
            postData.identifier = target.dataset.identifier;
        }

        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls[routeName]).post(postData);
            const result = await response.resolve();

            if (result.success) {
                Notification.success("File Sync", result.message);
                const formGroup = target.closest(".form-group");
                if (formGroup) {
                    formGroup.innerHTML =
                        '<div class="form-text"><span class="badge badge-success">Done</span></div>';
                }
            } else {
                Notification.error("File Sync", result.message || "An error occurred");
                target.disabled = false;
                target.classList.remove("disabled");
            }
        } catch {
            Notification.error("File Sync", "Request failed");
            target.disabled = false;
            target.classList.remove("disabled");
        }
    }
}

export default new StorageActions();
