<?php

namespace Pixelpeter\FilamentLanguageTabs\Forms\Components;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\On;
use ReflectionClass;
use ReflectionException;

class LanguageTabsOld extends Component
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

    /**
     * @var array<string, bool>
     */
    protected array $processedComponents = [];

    /**
     * @var array<class-string<Component>, \ReflectionProperty|false>
     */
    protected static array $hydrationHookPropertyCache = [];

    protected bool $hasBuilders = false;

    //protected ?string $currentLocale = null;

    public ?string $currentLocale;

    final public function __construct(array|Closure $schema)
    {
        $this->schema($schema);
    }

    public function schema(Closure|Schema|array $components): static
    {
        $this->hasBuilders = false;
        $this->currentLocale = null;

        if ($components instanceof Schema) {
            $components = $components->getComponents();
        }

        if ($components instanceof Closure) {
            $components = $this->evaluate($components);
        }

        $locales = $this->resolveLocales();
        $locales = array_unique($locales);

        $tabs = [];
        foreach ($locales as $index => $locale) {
            $tabs[] = Tab::make($this->resolveLocaleLabel($locale))
                ->key("tab_{$locale}")
                ->schema(
                    $this->tabfields($components, $locale)
                );
        }
        $this->currentLocale ??= $locales[0] ?? null;
        $tabsComponent = Tabs::make()
            ->key('language_tabs')
            ->extraAlpineAttributes([
                'x-data' => "{ currentLocale: null }",
                'x-effect' => 'currentLocale = tab.replace(\'tab_\', \'\'); $dispatch(\'language-tab-changed\', { currentLocale });console.log(currentLocale)'

            ])
            ->schema(
                $tabs
            );

        $actionsComponent = ActionsComponent::make([
            Action::make('refresh_language_tab_builders')
                ->label('Обновить блоки')
                ->color('gray')
                ->size('sm')
                ->button()
                ->visible(fn (): bool => $this->hasBuilders)
                ->action(fn () => $this->refreshBuilderComponents()),
        ])
            ->key('language_tabs_actions')
            ->alignEnd();

        $this->childComponents([
            $tabsComponent,
            $actionsComponent,
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

            if ($clone instanceof Builder) {
                $componentKey = "language_tabs.{$componentName}." . spl_object_id($clone);
                $clone->key($componentKey);
                $this->hasBuilders = true;
                $clone->meta('language_tabs', [
                    'attribute' => $base,
                    'locale' => $locale,
                ]);
                $this->prepareBuilderForLocale($clone, $base, $locale);
            } elseif ($clone instanceof Repeater) {
                $this->prepareRepeaterForLocale($clone, $base, $locale);
            } elseif ($clone instanceof Field) {
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

        if (!empty($configuredLocales)) {
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
                /** @var callable(array<int, string>) $refresh */
                $refresh = [$livewire, 'refreshFormData'];
                $refresh(["{$attribute}.{$locale}", $attribute]);
            }
        });
    }

    protected function normaliseAttributeState(Component $component, string $attribute, mixed $rawState): array
    {
        if (in_array($attribute, $this->normalisedAttributes, true) && is_array($rawState)) {
            return $rawState;
        }

        $record = $this->resolveRecord($component);
        $translations = $record?->getTranslations($attribute);

        if (!is_array($translations) || empty($translations)) {
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
                return (string)$record->baseLocale();
            }

            if (property_exists($record, 'baseLocale')) {
                return (string)$record->baseLocale;
            }
        }

        $locales = $this->resolveLocales();

        return $locales[0] ?? config('app.locale', 'en');
    }

    protected function resolveRecord(Component $component): ?Model
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

    protected function prepareBuilderForLocale(
        Builder $builder,
        string $attribute,
        string $locale
    ): void {
        $componentId = spl_object_id($builder);
        $processKey = "{$componentId}_{$locale}";

        if (isset($this->processedComponents[$processKey])) {
            return;
        }

        $this->processedComponents[$processKey] = true;

        $existingHydrated = $this->getExistingHydrationHook($builder);

        $builder->afterStateHydrated(function (
            Builder $component,
            ?array $state
        ) use ($existingHydrated, $attribute, $locale): void {
            if ($existingHydrated !== null) {
                $existingHydrated($component, $state);
            }

            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $attributeState = $get($attribute);

            if (! is_array($attributeState)) {
                $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
                $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
            }

            $localeData = Arr::get($attributeState, $locale);

            // ВСЕГДА устанавливаем state для сброса предыдущей локали
            // Если данных нет (null) - передаем [] для дефолтного UI Builder
            $component->state(is_array($localeData) ? $localeData : []);
        });

        $builder->afterStateUpdated(function (Builder $component, $state) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $translations = $get($attribute);

            if (! is_array($translations)) {
                $translations = [];
            }

            // Сохраняем state, пустой state = пустой массив для Builder/Repeater
            $translations[$locale] = $state ?? [];

            $set($attribute, $translations, shouldCallUpdatedHooks: true);

            $livewire = $component->getLivewire();

            if (method_exists($livewire, 'refreshFormData')) {
                /** @var callable(array<int, string>) $refresh */
                $refresh = [$livewire, 'refreshFormData'];
                $refresh(["{$attribute}.{$locale}", $attribute]);
            }
        });
    }

    protected function prepareRepeaterForLocale(
        Repeater $repeater,
        string $attribute,
        string $locale
    ): void {
        $componentId = spl_object_id($repeater);
        $processKey = "{$componentId}_{$locale}";

        if (isset($this->processedComponents[$processKey])) {
            return;
        }

        $this->processedComponents[$processKey] = true;

        $existingHydrated = $this->getExistingHydrationHook($repeater);

        $repeater->afterStateHydrated(function (
            Repeater $component,
            ?array $state
        ) use ($existingHydrated, $attribute, $locale): void {
            if ($existingHydrated !== null) {
                $existingHydrated($component, $state);
            }

            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $attributeState = $get($attribute);

            if (! is_array($attributeState)) {
                $attributeState = $this->normaliseAttributeState($component, $attribute, $attributeState);
                $set($attribute, $attributeState, shouldCallUpdatedHooks: false);
            }

            $localeData = Arr::get($attributeState, $locale);

            // ВСЕГДА устанавливаем state для сброса предыдущей локали
            // Если данных нет (null) - передаем [] для дефолтного UI Builder
            $component->state(is_array($localeData) ? $localeData : []);
        });

        $repeater->afterStateUpdated(function (Repeater $component, $state) use ($attribute, $locale): void {
            $get = $component->makeGetUtility();
            $set = $component->makeSetUtility();

            $translations = $get($attribute);

            if (! is_array($translations)) {
                $translations = [];
            }

            // Сохраняем state, пустой state = пустой массив для Builder/Repeater
            $translations[$locale] = $state ?? [];

            $set($attribute, $translations, shouldCallUpdatedHooks: true);

            $livewire = $component->getLivewire();

            if (method_exists($livewire, 'refreshFormData')) {
                /** @var callable(array<int, string>) $refresh */
                $refresh = [$livewire, 'refreshFormData'];
                $refresh(["{$attribute}.{$locale}", $attribute]);
            }
        });
    }

    #[On('languageTabChanged')]
    public function changeLocale(string $locale): void
    {
        dd($locale);
        // Обновляем текущую локаль
        $this->currentLocale = $locale;

        // Устанавливаем app locale
        app()->setLocale($locale);

        // Обновляем все Builder компоненты для новой локали
        $this->refreshBuilderComponents();
    }

    protected function refreshBuilderComponents(): void
    {
        dd($this->currentLocale);
        $schema = $this->getChildSchema();

        if (! $schema) {
            return;
        }

        foreach ($this->collectBuilderComponents($schema) as $component) {
            if ($component->isHidden()) {
                continue;
            }

            $meta = $component->getMeta('language_tabs');

            if (! is_array($meta)) {
                continue;
            }

            $attribute = $meta['attribute'] ?? null;
            $locale = $meta['locale'] ?? null;

             if ($this->currentLocale !== null && $locale !== $this->currentLocale) {
                 continue;
             }

            if (! is_string($attribute) || ! is_string($locale)) {
                continue;
            }

            $get = $component->makeGetUtility();

            $translations = $get($attribute);

            if (! is_array($translations)) {
                $translations = [];
            }

            $localeData = $translations[$locale] ?? [];

            if (! is_array($localeData)) {
                $localeData = [];
            }

            $component->rawState($localeData);

            foreach ($localeData as $itemKey => $itemData) {
                if (! is_array($itemData)) {
                    continue;
                }

                $component->getChildSchema($itemKey)?->fill($itemData['data'] ?? []);
            }

            $component->callAfterStateUpdated();
        }
    }

    /**
     * @return array<int, Builder>
     */
    protected function collectBuilderComponents(Schema $schema): array
    {
        $builders = [];

        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            if ($component instanceof Builder) {
                $builders[] = $component;

                continue;
            }

            foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
                $builders = [
                    ...$builders,
                    ...$this->collectBuilderComponents($childSchema),
                ];
            }
        }

        return $builders;
    }

    protected function getExistingHydrationHook(Component $component): ?Closure
    {
        $class = $component::class;

        if (array_key_exists($class, static::$hydrationHookPropertyCache)) {
            $property = static::$hydrationHookPropertyCache[$class];

            if ($property === false) {
                return null;
            }

            $hook = $property->getValue($component);

            return $hook instanceof Closure ? $hook : null;
        }

        try {
            $reflection = new ReflectionClass($component);
            if (! $reflection->hasProperty('afterStateHydrated')) {
                static::$hydrationHookPropertyCache[$class] = false;

                return null;
            }

            $property = $reflection->getProperty('afterStateHydrated');
            $property->setAccessible(true);

            static::$hydrationHookPropertyCache[$class] = $property;

            $hook = $property->getValue($component);

            return $hook instanceof Closure ? $hook : null;
        } catch (ReflectionException) {
            static::$hydrationHookPropertyCache[$class] = false;

            return null;
        }
    }

    public function render(): View
    {
        $this->processedComponents = [];

        return parent::render();
    }

}
