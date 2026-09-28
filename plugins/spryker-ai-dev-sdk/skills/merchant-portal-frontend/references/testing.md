# Testing and linting

Unit specs run on Jest with `jest-preset-angular` through `@angular-builders/jest`. The config is
`vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/jest.config.mjs`, with roots `src/Pyz` and
`vendor/spryker`, and `passWithNoTests: true`.

## House spec pattern: TestHost + NO_ERRORS_SCHEMA

Test the component through a host template shaped like its Twig usage. That exercises inputs and
content slots the way the page does. Core example:
`vendor/spryker/agent-dashboard-merchant-portal-gui/src/Spryker/Zed/AgentDashboardMerchantPortalGui/Presentation/Components/app/agent-bar/agent-bar.component.spec.ts`.

```ts
import { Component, NO_ERRORS_SCHEMA } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';

import { MerchantNoteComponent } from './merchant-note.component';

@Component({
    standalone: false,
    template: `
        <mp-merchant-note [note]="note">
            <span title class="title-slot"></span>
        </mp-merchant-note>
    `,
})
class TestHostComponent {
    note = '';
}

describe('MerchantNoteComponent', () => {
    let fixture: ComponentFixture<TestHostComponent>;

    beforeEach(() => {
        TestBed.configureTestingModule({
            declarations: [MerchantNoteComponent, TestHostComponent],
            schemas: [NO_ERRORS_SCHEMA],
        });

        fixture = TestBed.createComponent(TestHostComponent);
    });

    it('should render the `title` slot into `.mp-merchant-note__title`', () => {
        fixture.detectChanges();

        expect(fixture.debugElement.query(By.css('.mp-merchant-note__title .title-slot'))).toBeTruthy();
    });

    it('should render the note text', () => {
        fixture.componentInstance.note = 'Hello';
        fixture.detectChanges(); // OnPush: re-render only after detectChanges

        expect(fixture.debugElement.query(By.css('.mp-merchant-note__text')).nativeElement.textContent).toContain('Hello');
    });
});
```

- The host and the component under test are both `standalone: false` and both go in `declarations`.
- `NO_ERRORS_SCHEMA` lets child `<spy-*>` elements go unresolved. As a side effect, a typo in a child tag
  is not caught, so assert on rendered output.
- Cover what Twig depends on: each input (including absent or default), each output, each content slot,
  and loading, empty and error states.
- For services, use `TestBed.inject(...)` and `HttpTestingController`. Check
  `node_modules/@spryker/<pkg>/testing/` for mocks before writing your own.

## Running

```bash
docker/sdk cli npm run mp:test                                     # whole suite (project + core specs)
docker/sdk cli npm run mp:test -- --test-path-patterns=merchant-note
docker/sdk cli npm run mp:test -- -t 'should render the note text'
```

- The flag is kebab-case `--test-path-patterns` with `@angular-builders/jest` 22, as pinned in
  `package-lock.json`. Older builder majors accept `--test-path-pattern`. If in doubt, check
  `npm run mp:test -- --help`.
- `Schema validation failed ... "/zoneless" must be array` means `node_modules` is older than the lockfile
  (angular.json was written for builder 22). Run `docker/sdk cli npm install`; do not edit angular.json.
- Because of `passWithNoTests`, read the summary to confirm your spec ran.
- Browser journeys belong in Cypress (`cypress-tests` skill), not Jest.

## Lint: confirm coverage before citing `mp:lint`

`mp:lint` runs `npx eslint` over `src/Pyz/Zed/*/Presentation/Components/**/*.{ts,html}`, using the project
root `eslint.config.mp.mjs` if it exists and the packaged
`vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/libs/lint/eslint.config.mjs` otherwise. It
ignores extra arguments, so `-- --fix` does nothing.

In zed-ui 4.3.0, the packaged config's `files` patterns only match the monorepo layout. For every project
file ESLint prints `File ignored because no matching configuration was supplied`, and it still exits 0.
Check a file:

```bash
npx eslint --no-config-lookup --config vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/libs/lint/eslint.config.mjs \
  --print-config src/Pyz/Zed/{Module}/Presentation/Components/app/{name}/{name}.component.ts   # "undefined" = not covered
```

A project fixes this with a root `eslint.config.mp.mjs` that re-points the packaged core blocks at the
project layout. The core blocks also type-check against the spec and lint TS programs, so spec files are
covered too:

```js
import { merchantPortalCoreConfig } from './vendor/spryker/zed-ui/src/Spryker/Zed/ZedUi/FrontendBuilder/libs/lint/eslint.config.mjs';

export default merchantPortalCoreConfig.map((block) => ({
    ...block,
    files: block.files.map((pattern) => pattern.replace('src/Spryker/*/src/Spryker/Zed/', 'src/Pyz/Zed/')),
}));
```

Autofix one file with the same config:
`npx eslint --no-config-lookup --config eslint.config.mp.mjs --fix <file>`.

A parsing error like `The file was not found in any of the provided project(s)` means the file is not
reachable from any `entry.ts` import chain and is not a spec. Import it, or delete it if it is dead code.
