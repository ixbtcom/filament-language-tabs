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

class LanguageTabs extends Tabs
{
    use InteractsWithForms;

    /**
     * Исходная схема переводимых полей (массив / замыкание / Schema).
     */
    protected array | Closure | Schema $translatableSchema = [];

    /**
     * Принимаем схему от пользователя (просто сохраняем).
     */
    public function schema(array | Closure | Schema $schema): static
    {
        $this->translatableSchema = $schema;

        return $this;
    }

    /**
     * Превращаем исходную схему в конкретные экземпляры компонентов.
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
     * Для каждой локали создаём таб, клонируем поля и назначаем им локализованный statePath.
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

                $fields[] = $clone;
            }

            $tabs[] = Tab::make($this->resolveLocaleLabel($locale))
                ->key("tab_{$locale}")
                ->schema($fields);
        }

        return $tabs;
    }

    /**
     * Разбираем исходный statePath (может быть вложенным) и подставляем локализованный хвост.
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

        return $basePath
            ? "$basePath.$relativePath"
            : $relativePath;
    }

    /**
     * Получаем относительный путь в зависимости от драйвера, указанного в $model->translatable.
     */
    protected function resolveAttributeStatePath(string $attribute, string $locale): string
    {
        $model = $this->resolveModel();

        if (! $model instanceof Model) {
            return "$attribute.$locale";
        }

        if (! method_exists($model, 'isTranslatableAttribute') || ! $model->isTranslatableAttribute($attribute)) {
            return $attribute;
        }

        $definition = $this->resolveAttributeDefinition($model, $attribute) ?? [];

        $driver = $definition['driver'] ?? config('translatable.default_driver', 'json');
        $storageColumn = $definition['storage'] ?? $this->resolveStorageColumn($model);
        $baseLocale = $this->resolveBaseLocale($model);

        return match ($driver) {
            'hybrid' => $locale === $baseLocale
                ? $attribute
                : "$storageColumn.$locale.$attribute",
            'extra_only' => "$storageColumn.$locale.$attribute",
            default => "$attribute.$locale",
        };
    }

    /**
     * Вытаскиваем конфигурацию атрибута из массива $model->translatable.
     */
    protected function resolveAttributeDefinition(Model $record, string $attribute): ?array
    {
        if (! property_exists($record, 'translatable')) {
            return null;
        }

        $translatable = $record->translatable;

        if (array_key_exists($attribute, $translatable)) {
            $value = $translatable[$attribute];

            return is_array($value) ? $value : [];
        }

        if (is_array($translatable) && in_array($attribute, $translatable, true)) {
            return [];
        }

        return null;
    }

    /**
     * Список локалей: сначала смотрим на модель, затем на конфиг.
     */
    protected function resolveLocales(): array
    {
        $model = $this->resolveModel();

        if (! $model) {
            $configuredLocales = config('filament-language-tabs.default_locales', []);

            if (! empty($configuredLocales)) {
                return array_values(array_unique($configuredLocales));
            }

            return [config('app.locale', 'en')];
        }

        // Модель задаёт локали через метод getTranslatableLocales() или property
        if (method_exists($model, 'getTranslatableLocales')) {
            $locales = $model->getTranslatableLocales();

            if (! empty($locales)) {
                return array_values(array_unique($locales));
            }
        }

        if (property_exists($model, 'translatableLocales') && is_array($model->translatableLocales)) {
            return array_values(array_unique($model->translatableLocales));
        }

        $configuredLocales = config('filament-language-tabs.default_locales', []);

        if (! empty($configuredLocales)) {
            return array_values(array_unique($configuredLocales));
        }

        return [config('app.locale', 'en')];
    }

    /**
     * Заголовок для таба: или из конфига, или просто верхний регистр локали.
     */
    protected function resolveLocaleLabel(string $locale): string
    {
        $labels = config('filament-language-tabs.locale_labels', []);

        if (array_key_exists($locale, $labels)) {
            return $labels[$locale];
        }

        return strtoupper($locale);
    }

    /**
     * Базовая локаль (нужна для HybridColumnDriver).
     */
    protected function resolveBaseLocale(?Model $record): string
    {
        if ($record) {
            if (method_exists($record, 'baseLocale')) {
                return (string) $record->baseLocale();
            }
        }

        $locales = $this->resolveLocales();

        return $locales[0] ?? config('app.locale', 'en');
    }

    /**
     * Колонка хранения переводов (если модель её задаёт).
     */
    protected function resolveStorageColumn(?Model $record): string
    {
        if ($record && method_exists($record, 'translationStorageColumn')) {
            return (string) $record->translationStorageColumn();
        }

        return config('translatable.storage_column', 'extra');
    }

    /**
     * Пытаемся получить текущую модель (record или новый экземпляр).
     */
    protected function resolveModel(): ?Model
    {
        $livewire = $this->getLivewire();

        if (method_exists($livewire, 'getRecord')) {
            $record = $livewire->getRecord();

            if ($record instanceof Model) {
                return $record;
            }
        }

        $modelClass = null;

        if (method_exists($livewire, 'getModel')) {
            $model = $livewire->getModel();

            if ($model instanceof Model) {
                return $model;
            }

            if (is_string($model)) {
                $modelClass = $model;
            }
        } elseif (property_exists($livewire, 'model')) {
            $model = $livewire->model;

            if ($model instanceof Model) {
                return $model;
            }

            if (is_string($model)) {
                $modelClass = $model;
            }
        }

        if ($modelClass && class_exists($modelClass)) {
            return app($modelClass);
        }

        return null;
    }
}
