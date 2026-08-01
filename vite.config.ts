import inertia from "@inertiajs/vite";
import { wayfinder } from "@laravel/vite-plugin-wayfinder";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import laravel from "laravel-vite-plugin";
import { bunny } from "laravel-vite-plugin/fonts";
import { defineConfig } from "vite-plus";

export default defineConfig(({ command, isSsrBuild }) => ({
  // Build only. The runtime image ships bootstrap/ssr without node_modules, so the bundle has to be
  // self-contained. In dev it would drag CJS packages like react through the ESM module runner,
  // which fails on their `module.exports` entry points.
  ssr: {
    // `false` is not an accepted value here, so dev gets an empty list instead.
    noExternal: command === "build" ? true : [],
  },
  build: {
    // Without these, a production RUM error is a stack trace through minified chunk names and is not
    // actionable. The .map files are uploaded to OpenObserve by `php artisan rum:sourcemaps` and then deleted from
    // public/build by the same task, so they are never served to browsers.
    //
    // Client only: nothing consumes an SSR source map. `php artisan rum:sourcemaps` globs public/build, the Dockerfile
    // deletes what it finds, and RUM reports browser stacks — so emitting them for the SSR build was 5 MB
    // of work discarded on every build.
    sourcemap: !isSsrBuild,
  },
  fmt: {
    ignorePatterns: [],
    sortImports: true,
    sortPackageJson: true,
    sortTailwindcss: true,
  },
  lint: {
    env: {
      browser: true,
      builtin: true,
    },
    ignorePatterns: [
      "bootstrap/ssr",
      "public",
      "resources/js/actions/**",
      "resources/js/components/ui/*",
      "resources/js/routes/**",
      "resources/js/wayfinder/**",
      "resources/views/mail/*",
      "vendor",
    ],
    jsPlugins: ["@stylistic/eslint-plugin"],
    plugins: ["oxc", "typescript", "unicorn", "react", "import"],
    options: {
      typeAware: true,
      typeCheck: true,
    },
    overrides: [
      {
        files: ["**/*.ts", "**/*.tsx", "**/*.mts", "**/*.cts"],
        rules: {
          "constructor-super": "off",
          "getter-return": "off",
          "no-class-assign": "off",
          "no-const-assign": "off",
          "no-dupe-class-members": "off",
          "no-dupe-keys": "off",
          "no-func-assign": "off",
          "no-import-assign": "off",
          "no-new-native-nonconstructor": "off",
          "no-obj-calls": "off",
          "no-redeclare": "off",
          "no-setter-return": "off",
          "no-this-before-super": "off",
          "no-undef": "off",
          "no-unreachable": "off",
          "no-unsafe-negation": "off",
          "no-var": "error",
          "no-with": "off",
          "prefer-const": "error",
          "prefer-rest-params": "error",
          "prefer-spread": "error",
        },
      },
    ],
    rules: {
      "constructor-super": "error",
      curly: ["error", "all"],
      "for-direction": "error",
      "getter-return": "error",
      "no-async-promise-executor": "error",
      "no-case-declarations": "error",
      "no-class-assign": "error",
      "no-compare-neg-zero": "error",
      "no-cond-assign": "error",
      "no-const-assign": "error",
      "no-constant-binary-expression": "error",
      "no-constant-condition": "error",
      "no-control-regex": "error",
      "no-debugger": "error",
      "no-delete-var": "error",
      "no-dupe-class-members": "error",
      "no-dupe-else-if": "error",
      "no-dupe-keys": "error",
      "no-duplicate-case": "error",
      "no-empty": "error",
      "no-empty-character-class": "error",
      "no-empty-pattern": "error",
      "no-empty-static-block": "error",
      "no-ex-assign": "error",
      "no-extra-boolean-cast": "error",
      "no-fallthrough": "error",
      "no-func-assign": "error",
      "no-global-assign": "error",
      "no-import-assign": "error",
      "no-invalid-regexp": "error",
      "no-irregular-whitespace": "error",
      "no-loss-of-precision": "error",
      "no-misleading-character-class": "error",
      "no-new-native-nonconstructor": "error",
      "no-nonoctal-decimal-escape": "error",
      "no-obj-calls": "error",
      "no-prototype-builtins": "error",
      "no-redeclare": "error",
      "no-regex-spaces": "error",
      "no-self-assign": "error",
      "no-setter-return": "error",
      "no-shadow-restricted-names": "error",
      "no-sparse-arrays": "error",
      "no-this-before-super": "error",
      "no-undef": "error",
      "no-unexpected-multiline": "error",
      "no-unreachable": "error",
      "no-unsafe-finally": "error",
      "no-unsafe-negation": "error",
      "no-unsafe-optional-chaining": "error",
      "no-unused-labels": "error",
      "no-unused-private-class-members": "error",
      "no-unused-vars": "error",
      "no-useless-backreference": "error",
      "no-useless-catch": "error",
      "no-useless-escape": "error",
      "no-with": "error",
      "require-yield": "error",
      "use-isnan": "error",
      "valid-typeof": "error",
      "no-array-constructor": "error",
      "no-unused-expressions": "error",
      "import/consistent-type-specifier-style": ["error", "prefer-top-level"],
      "@stylistic/padding-line-between-statements": [
        "error",
        {
          blankLine: "always",
          prev: "*",
          next: "if",
        },
        {
          blankLine: "always",
          prev: "if",
          next: "*",
        },
        {
          blankLine: "always",
          prev: "*",
          next: "return",
        },
        {
          blankLine: "always",
          prev: "return",
          next: "*",
        },
        {
          blankLine: "always",
          prev: "*",
          next: "for",
        },
        {
          blankLine: "always",
          prev: "for",
          next: "*",
        },
        {
          blankLine: "always",
          prev: "*",
          next: "while",
        },
        {
          blankLine: "always",
          prev: "while",
          next: "*",
        },
        {
          blankLine: "always",
          prev: "*",
          next: "do",
        },
        {
          blankLine: "always",
          prev: "do",
          next: "*",
        },
        {
          blankLine: "always",
          prev: "*",
          next: "switch",
        },
        {
          blankLine: "always",
          prev: "switch",
          next: "*",
        },
        {
          blankLine: "always",
          prev: "*",
          next: "try",
        },
        {
          blankLine: "always",
          prev: "try",
          next: "*",
        },
        {
          blankLine: "always",
          prev: "*",
          next: "throw",
        },
        {
          blankLine: "always",
          prev: "throw",
          next: "*",
        },
      ],
      "react/exhaustive-deps": "warn",
      "react/rules-of-hooks": "error",
      "typescript/ban-ts-comment": "error",
      "typescript/no-duplicate-enum-values": "error",
      "typescript/no-empty-object-type": "error",
      "typescript/no-explicit-any": "off",
      "typescript/no-extra-non-null-assertion": "error",
      "typescript/no-misused-new": "error",
      "typescript/no-namespace": "error",
      "typescript/no-non-null-asserted-optional-chain": "error",
      "typescript/no-require-imports": "error",
      "typescript/no-this-alias": "error",
      "typescript/no-unnecessary-type-constraint": "error",
      "typescript/no-unsafe-declaration-merging": "error",
      "typescript/no-unsafe-function-type": "error",
      "typescript/no-wrapper-object-types": "error",
      "typescript/prefer-as-const": "error",
      "typescript/prefer-namespace-keyword": "error",
      "typescript/triple-slash-reference": "error",
      "typescript/consistent-type-imports": [
        "error",
        {
          prefer: "type-imports",
          fixStyle: "separate-type-imports",
        },
      ],
    },
  },
  plugins: [
    laravel({
      input: ["resources/css/app.css", "resources/js/app.tsx"],
      refresh: true,
      fonts: [
        bunny("Instrument Sans", {
          weights: [400, 500, 600],
        }),
      ],
    }),
    inertia(),
    react(),
    tailwindcss(),
    wayfinder({
      formVariants: true,
    }),
  ],
}));
