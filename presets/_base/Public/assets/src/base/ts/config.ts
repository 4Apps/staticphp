export const CONFIG = {
    environment: APP_ENV,

    // Injected at bundle time from .build_info.json (see scripts/build_info.bash).
    // These used to be hardcoded here and rewritten by a pre-commit hook, which meant the
    // committed values were always one commit behind and conflicted on every merge.
    version: APP_VERSION,
    git_commit_hash: APP_GIT_COMMIT_HASH,
    git_commit_date: APP_GIT_COMMIT_DATE
};
