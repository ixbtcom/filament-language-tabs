# Builder Locale Refresh Investigation

## Observed Behaviour
- Adding or editing content inside a `Builder` field embedded in `LanguageTabs` leaves the per-locale badge unchanged even though the raw Builder state mutates.
- After a full form refresh (reload / Livewire remount) the badge reflects the new data, which shows that persistence works but the reactive update cycle misses the change.

## Root Cause Analysis
- `LanguageTabs::prepareFieldForLocale()` assigns a new `afterStateHydrated` closure to every `Field` clone (`src/Forms/Components/LanguageTabs.php:134`), **replacing** any hydrator previously set on the component. Container fields such as `Filament\Forms\Components\Builder` define a hydrator in `setUp()` to normalise data and to replace numeric indexes with UUID keys (`../libs/forms/src/Components/Builder.php:104`). After our override the Builder never executes that logic, so its internal state remains the raw JSON array.
- Because the Builder’s state is left in this raw shape, the subsequent `$component->state(Arr::get($attributeState, $locale))` (`src/Forms/Components/LanguageTabs.php:148`) assigns `null` instead of the Builder default of `[]`. Later updates add data to a structure that was never normalised, so when `$set($attribute, $translations, shouldCallUpdatedHooks: true)` runs (`src/Forms/Components/LanguageTabs.php:163`), the diff for `attribute.locale` does not reflect the new array payload and the badge logic never receives an updated snapshot.
- The issue only appears with array-based fields (Builder, Repeater, Grid) because scalar inputs do not rely on the missing hydrator and therefore propagate their state correctly.

## Detailed Flow
1. Hydration
   - Builder default: converts JSON `[['type' => ..., 'data' => [...]]]` into a UUID-keyed array so per-item Livewire keys stay stable.
   - Current plugin: overrides the hydrator, skips the conversion, and immediately assigns `Arr::get($attributeState, $locale)` (often `null`) to the Builder state, losing UUID keys and any type-normalisation.
2. Update
   - Editing a block mutates `content.en.{uuid}.data.*`. Without UUID re-keying the mutation does not bubble up as a state change on the locale slice.
   - `refreshFormData([$attribute])` rehydrates `content`, but the badge logic typically watches `attribute.locale`, so the indicator stays stale.

## Recommendations
1. **Preserve native hydrators**
   - Capture the existing `afterStateHydrated` closure before calling `prepareFieldForLocale()` and invoke it inside the new closure. Reflection is acceptable here, e.g.
     ```php
     $original = (fn () => $this->afterStateHydrated)->call($field);
     $field->afterStateHydrated(function (Field $component) use ($original, ...) {
         $original && $component->evaluate($original);
         // LanguageTabs logic…
     });
     ```
   - Provide a similar shim for other container fields that register their own hydrators (Repeater, Grid, KeyValue) to avoid regressions.
2. **Normalise array states explicitly**
   - When the evaluated locale state is `null` but the field expects an array (Builder expects `[]`), set an empty array before handing control back to the component so its actions operate on the correct shape.
3. **Refresh locale-specific paths**
   - Replace `refreshFormData([$attribute])` with `refreshFormData(["{$attribute}.{$locale}", $attribute])` to force Livewire to recompute both the aggregate attribute and the per-locale slice.
4. **Regression tests**
   - Spin up a Livewire test with a Builder inside `LanguageTabs`, perform an inline update, and assert that `data()['content']['en']` changes and that the badge callback is invoked. Reproduce the same for a nested Repeater to guard against future regressions.

## Follow-up Checks
- Audit other components for overwritten hydration hooks (e.g. file uploads, RichEditor, KeyValue). Each may require the same preservation logic.
- Document the behaviour so contributors understand why array-based fields need special handling inside `LanguageTabs`.
