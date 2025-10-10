<?php

namespace Pixelpeter\FilamentLanguageTabs\Forms\Components;

use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Translatable\Drivers\ExtraOnlyDriver;
use Spatie\Translatable\Drivers\HybridColumnDriver;

class LanguageTabsold2 extends Tabs
{
    use InteractsWithForms;

    /**
     * Schema containing the components that should be translated.
     */
    protected array | Closure | Schema $translatableSchema = [];

    public function schema(array|Closure|Schema $schema): static
    {
        return $this->translatableSchema($schema);
    }

    /**
     * Set the schema to be translated.
     */
    public function translatableSchema(array | Closure | Schema $schema): static
    {
        $this->translatableSchema = $schema;

        return $this;
    }

    /**
     * Retrieve the components from the provided schema.
     *
     * @return array<int, Field|Builder|Repeater>
     */
    protected function getTranslatableComponents(): array
    {
        $schema = $this->evaluate($this->translatableSchema);

        if ($schema instanceof Schema) {
            return $schema->getComponents();
        }

        return is_array($schema) ? $schema : [];
    }

    /**
     * Build tabs for each configured locale.
     */
    public function getDefaultChildComponents(): array
    {
        $components = $this->getTranslatableComponents();
        $locales = $this->resolveLocales();

        $tabs = [];

        foreach ($locales as $locale) {
            $fields = [];

            foreach ($components as $component) {
                $clone = $component->getClone();
                $attribute = $clone->getName();

                $statePath = $this->resolveStatePath($clone, $attribute, $locale);

                $clone
                    ->name("{$attribute}_{$locale}")
                    ->statePath($statePath);

                if ($clone instanceof Builder) {
                    $this->prepareBuilderForLocale($clone, $attribute, $locale);
                } elseif ($clone instanceof Repeater) {
                    $this->prepareRepeaterForLocale($clone, $attribute, $locale);
                }

                $fields[] = $clone;
            }

            $tabs[] = Tab::make($this->resolveLocaleLabel($locale))
                ->key("tab_{$locale}")
                ->schema($fields);
        }

        return $tabs;
    }

    /**
     * Determine the correct state path for the given attribute and locale.
     */
    protected function resolveStatePath(Field $component, string $attribute, string $locale): string
    {
        $originalPath = $component->getStatePath(isAbsolute: false) ?: $attribute;

        $basePath = Str::contains($originalPath, '.')
            ? Str::beforeLast($originalPath, '.')
            : null;

        $resolvedAttribute = Str::contains($originalPath, '.')
            ? Str::afterLast($originalPath, '.')
            : $attribute;

        $relativePath = $this->resolveAttributeStatePath($resolvedAttribute, $locale);

        if ($basePath) {
            return "$basePath.$relativePath";
        }

        return $relativePath;
    }

    protected function resolveAttributeStatePath(string $attribute, string $locale): string
    {
        $livewire = $this->getLivewire();

        if (! method_exists($livewire, 'getRecord')) {
            return $this->resolveStatePathFromConfig($attribute, $locale);
        }

        $record = $livewire->getRecord();

        if (! $record instanceof Model) {
            return $this->resolveStatePathFromConfig($attribute, $locale);
        }

        if (! method_exists($record, 'driver') || ! method_exists($record, 'isTranslatableAttribute')) {
            return $attribute;
        }

        if (! $record->isTranslatableAttribute($attribute)) {
            return $attribute;
        }

        try {
            $driver = $record->driver($attribute);
            $baseLocale = $this->resolveBaseLocale($record);

            if ($driver instanceof HybridColumnDriver) {
                if ($locale === $baseLocale) {
                    return $attribute;
                }

                $storageColumn = $driver->resolveStorageColumn($record);

                return "$storageColumn.$locale.$attribute";
            }

            if ($driver instanceof ExtraOnlyDriver) {
                $storageColumn = $driver->resolveStorageColumn($record);

                return "$storageColumn.$locale.$attribute";
            }
        } catch (\Throwable $exception) {
            return "$attribute.$locale";
        }

        return "$attribute.$locale";
    }

    protected function resolveStatePathFromConfig(string $attribute, string $locale): string
    {
        $defaultDriver = config('translatable.default_driver', 'json');
        $storageColumn = config('translatable.storage_column', 'extra');
        $baseLocale = config('app.locale', 'en');

        if ($defaultDriver === 'hybrid') {
            return $locale === $baseLocale
                ? $attribute
                : "$storageColumn.$locale.$attribute";
        }

        if ($defaultDriver === 'extra_only') {
            return "$storageColumn.$locale.$attribute";
        }

        return "$attribute.$locale";
    }

    protected function resolveLocales(): array
    {
        $configuredLocales = config('filament-language-tabs.default_locales', []);

        if (! empty($configuredLocales)) {
            return array_values(array_unique($configuredLocales));
        }

        return [config('app.locale', 'en')];
    }

    protected function resolveLocaleLabel(string $locale): string
    {
        $labels = config('filament-language-tabs.locale_labels', []);

        if (array_key_exists($locale, $labels)) {
            return $labels[$locale];
        }

        return strtoupper($locale);
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

    protected function prepareBuilderForLocale(Builder $builder, string $attribute, string $locale): void
    {
        $builder->key("language_tabs.{$attribute}_{$locale}." . spl_object_id($builder));
        $builder->meta('language_tabs', [
            'attribute' => $attribute,
            'locale' => $locale,
        ]);

        $requiredLocales = config('filament-language-tabs.required_locales', []);

        if (! in_array($locale, $requiredLocales, true)) {
            $builder->required(false);
        }
    }

    protected function prepareRepeaterForLocale(Repeater $repeater, string $attribute, string $locale): void
    {
        $repeater->key("language_tabs.{$attribute}_{$locale}." . spl_object_id($repeater));
        $repeater->meta('language_tabs', [
            'attribute' => $attribute,
            'locale' => $locale,
        ]);

        $requiredLocales = config('filament-language-tabs.required_locales', []);

        if (! in_array($locale, $requiredLocales, true)) {
            $repeater->required(false);
        }
    }
}
