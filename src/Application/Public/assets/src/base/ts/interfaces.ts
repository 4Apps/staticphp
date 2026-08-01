import type { Utils } from 'base/utils';

/**
 * Window augmentations for values the framework or the application attaches globally.
 *
 * Build-time constants (APP_ENV and friends) are declared in assets/src/globals.d.ts
 * instead - declaring APP_ENV here as well made it a duplicate block-scoped binding.
 *
 * jQuery was declared here but is not a dependency of this project and nothing referenced
 * it, so an application that wants it should declare its own augmentation.
 */
declare global {
    interface Window {
        BASE_URI: string;
        BASE_URL: string;

        Utils: typeof Utils;

        // Populated by the application's own translation bootstrap, when it has one
        translateStrings?: Record<string, string>;

        // Optional bootstrap helpers, attached by application code
        helperBsTooltips?: () => void;
        helperBsPopovers?: () => void;
    }
}

export {};
