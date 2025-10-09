<?php

namespace Pixelpeter\FilamentLanguageTabs\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class LanguageTabs extends Component
{
    use InteractsWithForms;

    /**
     * @var view-string
     */
    /** @phpstan-ignore-next-line */
    protected string $view = 'filament-language-tabs::forms.components.language-tabs';

    /**
     * Keep track of attributes we've already normalised to avoid redundant work.
     *
     * @var array<int, string>
     */
    protected array $normalisedAttributes = [];

    final public function __construct(array|Closure $schema)
    {
        $this->schema($schema);
    }

    public function schema(Closure|Schema|array $components): static
    {
        if ($components instanceof Schema) {
            $components = $components->getComponents();
        }

        if ($components instanceof Closure) {
            $components = $this->evaluate($components);
        }

        $locales = $this->resolveLocales();
        $locales = array_unique($locales);

        $tabs = [];
        foreach ($locales as $locale) {
            $tabs[] = Tab::make($this->resolveLocaleLabel($locale))
                ->key("tab_{$locale}")
                ->schema(
                    $this->tabfields($components, $locale)
                );
        }
        $t = Tabs::make()
            ->key('language_tabs')
            ->schema(
                $tabs
            );
        $this->childComponents([
            $t,
        ]);

        return $this;
    }

    public static function make(array|Closure $schema): static
    {
        $static = app(static::class, ['schema' => $schema]);
        $static->configure();

        return $static;
    }

    protected function tabfields(array $components, string $locale): array
    {
        $required_locales = config('filament-language-tabs.required_locales', []);
        $required_locales = array_unique($required_locales);

        $tabfields = [];
        foreach ($components as $component) {
            $clone = clone $component;
            $base = $clone->getName();

            $componentName = "{$base}_{$locale}";
            $statePath = "{$base}.{$locale}";
            $clone->name($componentName);
            $clone->statePath($statePath);

            if ($clone instanceof Field) {
                $this->prepareFieldForLocale($clone, $base, $locale);
            }

            if (! in_array($locale, $required_locales, true)) {
                $clone->required(false);
            }

            $tabfields[] = $clone;
        }

        return $tabfields;
    }

    /**
     * Resolve locales from the active Filament panel (if available) or fallback to config.
     *
     * @return array<int, string>
     */
    protected function resolveLocales(): array
    {
        $configuredLocales = config('filament-language-tabs.default_locales', []);

        if (! empty($configuredLocales)) {
            return array_values(array_unique($configuredLocales));
        }

        return [config('app.locale')];
    }

    protected function resolveLocaleLabel(string $locale): string
    {
        $labels = config('filament-language-tabs.locale_labels', []);

        if (isset($labels[$locale])) {
            return $labels[$locale];
        }

        return strtoupper($locale);
    }

    protected function prepareFieldForLocale(Field $field, string $attribute, string $locale): void
    {
        $field->afterStateHydrated(function (Field $component) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $attributeState = $get($attribute);

            if (! is_array($attributeState)) {
                $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
                $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
            }

            $component->state(Arr::get($attributeState, $locale));
        });

        $field->afterStateUpdated(function (Field $component, $state) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $translations = $get($attribute);

            if (! is_array($translations)) {
                $translations = [];
            }

            $translations[$locale] = $state === '' ? null : $state;

            $set($attribute, $translations, shouldCallUpdatedHooks: true);

            $livewire = $component->getLivewire();

            if (method_exists($livewire, 'refreshFormData')) {
                /** @var callable(array<string>) $refresh */
                $refresh = [$livewire, 'refreshFormData'];
                $refresh([$attribute]);
            }
        });
    }

    protected function normaliseAttributeState(Field $component, string $attribute, mixed $rawState): array
    {
        if (in_array($attribute, $this->normalisedAttributes, true) && is_array($rawState)) {
            return $rawState;
        }

        $record = $this->resolveRecord($component);

        $translations = [];

        if ($record && method_exists($record, 'getTranslations')) {
            try {
                $translations = $record->getTranslations($attribute);
            } catch (\Throwable) {
                $translations = [];
            }
        }

        if (! is_array($translations) || empty($translations)) {
            if (is_array($rawState)) {
                $translations = $rawState;
            } elseif ($rawState !== null && $rawState !== '') {
                $baseLocale = $this->resolveBaseLocale($record);
                $translations = [$baseLocale => $rawState];
            } else {
                $translations = [];
            }
        }

        $locales = $this->resolveLocales();
        if (! empty($locales)) {
            $translations = Arr::only($translations, $locales);
        }

        foreach ($locales as $locale) {
            $translations[$locale] ??= null;
        }

        $this->normalisedAttributes[] = $attribute;

        return $translations;
    }

    protected function resolveBaseLocale(?Model $record): string
    {
        if ($record) {
            if (method_exists($record, 'baseLocale')) {
                return (string) $record->baseLocale();
            }

            if (property_exists($record, 'baseLocale')) {
                return (string) $record->baseLocale;
            }
        }

        $locales = $this->resolveLocales();

        return $locales[0] ?? config('app.locale', 'en');
    }

    protected function resolveRecord(Field $component): ?Model
    {
        $livewire = $component->getLivewire();

        if (method_exists($livewire, 'getRecord')) {
            return $livewire->getRecord();
        }

        if (property_exists($livewire, 'record')) {
            $record = $livewire->record;

            return $record instanceof Model ? $record : null;
        }

        return null;
    }
}
