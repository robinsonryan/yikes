import eslint from "@eslint/js";
import tseslint from "typescript-eslint";
import pluginVue from "eslint-plugin-vue";
import globals from "globals";

/**
 * The island's lint gate. Mirrors the app-side config (ccstake) so a developer
 * moving between the app and this package meets the same rules — notably the
 * two that catch people out, both inherited from tseslint's strict preset:
 * no non-null assertion (`!`), and `readonly T[]` over `ReadonlyArray<T>`.
 *
 * Deliberately narrower than the app config: no Inertia, no `route()` global,
 * and no prettier integration (this package does not use prettier).
 */
export default tseslint.config(
    eslint.configs.recommended,
    ...tseslint.configs.strict,
    ...tseslint.configs.stylistic,
    ...pluginVue.configs["flat/recommended"],
    {
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
    },
    {
        files: ["resources/js/**/*.vue"],
        languageOptions: {
            parserOptions: {
                parser: tseslint.parser,
            },
        },
    },
    {
        files: ["resources/js/**/__tests__/**/*.ts"],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        rules: {
            // The island ships single-word components by design — they live in
            // a shadow DOM under a `y-` prefixed host, not in a host app's
            // component namespace, so collisions are not possible.
            "vue/multi-word-component-names": "off",
            // This package indents 4, matching its PHP side. The plugin
            // defaults to 2, which would rewrite every template for no gain.
            "vue/html-indent": ["error", 4],
            // Layout rules that assume a formatter owns the file. ccstake gets
            // the same effect from eslint-config-prettier; this package has no
            // prettier, so they are switched off explicitly rather than by
            // installing a config for a tool that is not here.
            "vue/max-attributes-per-line": "off",
            "vue/singleline-html-element-content-newline": "off",
            "vue/attributes-order": "off",
            // Props are declared type-first with `withDefaults`. For a genuinely
            // optional prop (`label?`, `icon?` on YButton — a button is
            // icon-only OR label-only) `undefined` IS the meaningful default,
            // and inventing one would misrepresent the component's contract.
            "vue/require-default-prop": "off",
            "@typescript-eslint/no-unused-vars": [
                "error",
                {
                    argsIgnorePattern: "^_",
                    varsIgnorePattern: "^_",
                },
            ],
        },
    },
    {
        // The only v-html in the package, and it is guarded: every one of these
        // renders `checklistTextHtml()`, which HTML-escapes its input and then
        // re-admits ONLY `[label](href)` links whose href is a same-site path or
        // http(s) URL. The input is team-authored checklist YAML, not captured
        // user content. If that helper ever stops escaping, this exemption is
        // wrong — it is scoped to these two files so that stays reviewable.
        files: ["resources/js/pages/testing/Test.vue", "resources/js/pages/testing/Suite.vue"],
        rules: {
            "vue/no-v-html": "off",
        },
    },
    {
        ignores: [
            // Built island bundle — committed on purpose so composer consumers
            // never run npm, but generated, so never linted.
            "dist/**",
            "vendor/**",
            "node_modules/**",
        ],
    }
);
