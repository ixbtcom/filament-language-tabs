![](https://banners.beyondco.de/Filament%20Language%20Tabs.png?theme=light&packageManager=composer+require&packageName=pixelpeter%2Ffilament-language-tabs&pattern=architect&style=style_1&description=Group+multilingual+fields+into+tabs&md=1&showWatermark=0&fontSize=100px&images=translate)

# Filament Language Tabs

Лёгкий компонент для [Filament](https://filamentphp.com/), который собирает переводимые поля в табы. Работает поверх [ixbtcom/laravel-translatable](https://github.com/ixbtcom/laravel-translatable) и учитывает выбранный драйвер (`json`, `hybrid`, `extra_only`). Builder / EditorJS и любые другие поля переключаются мгновенно — без JavaScript костылей, кнопок «обновить» и ручного управления локалями.

## Возможности

- Наследуется от `Filament\Schemas\Components\Tabs`, поэтому полностью совместим с любыми контейнерами Filament.
- Читает конфигурацию перевода из `$model->translatable`, `baseLocale()`, `translationStorageColumn()`, `getTranslatableLocales()`.
- Поддерживает `json`, `hybrid`, `extra_only` драйверы ixbtcom/laravel-translatable.
- Автоматически строит `statePath` для каждого клона поля — EditorJS/Builder получают нужный JSON сразу.
- Не требует дополнительных JS-событий, кнопок «обновить» и т.д.

## Установка

```bash
composer require pixelpeter/filament-language-tabs
```

При необходимости опубликуйте конфиг (используется только если модель не задаёт список локалей):

```bash
php artisan vendor:publish --tag="filament-language-tabs-config"
```

## Требования

- Laravel 10+
- Filament 4.x
- [ixbtcom/laravel-translatable](https://github.com/ixbtcom/laravel-translatable)

## Быстрый пример

```php
use Pixelpeter\FilamentLanguageTabs\Forms\Components\LanguageTabs;

LanguageTabs::make('Translations')
    ->schema([
        TextInput::make('title')->label('Заголовок'),
        Textarea::make('subtitle')->label('Подзаголовок'),
        Builder::make('blocks'),
    ]);
```

Компонент клонирует каждое поле для всех локалей и назначает `statePath` в соответствии с драйвером из модели.

## Настройка модели

```php
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Project extends Model
{
    use HasTranslations;

    public array $translatable = [
        'title' => [
            'driver' => 'hybrid', // базовая локаль в колонке title, остальные в extra.locale.title
        ],
        'subtitle' => [
            'driver' => 'extra_only',
        ],
        'blocks', // json по умолчанию
    ];

    public function baseLocale(): string
    {
        return 'ru';
    }

    public function translationStorageColumn(): string
    {
        return 'extra';
    }

    // необязательно, но можно вернуть явный список локалей
    public function getTranslatableLocales(): array
    {
        return ['ru', 'en'];
    }
}
```

## Использование в Filament Resource

```php
class ProjectResource extends Resource
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Content')
                    ->schema([
                        LanguageTabs::make('Переводы')
                            ->schema([
                                TextInput::make('title')->label('Заголовок'),
                                MarkdownEditor::make('subtitle')->label('Подзаголовок'),
                                Builder::make('blocks'),
                            ])->columnSpanFull(),
                    ]),
            ]);
    }
}
```

## Как это работает

- `LanguageTabs` наследуется от `Tabs` и переопределяет `getDefaultChildComponents()`.
- Для каждой локали клонируются оригинальные поля, а `statePath` пересчитывается на основе драйвера:
  - `json`: `attribute.locale`
  - `hybrid`: базовая локаль в `attribute`, остальные в `storage.locale.attribute`
  - `extra_only`: все локали в `storage.locale.attribute`
- `Builder` / EditorJS получают данные нужной локали сразу, без дополнительных действий.

## Конфигурация (опционально)

`config/filament-language-tabs.php`

```php
return [
    'default_locales' => ['ru', 'en'], // используется только если модель не отдаёт локали
    'locale_labels' => [
        'ru' => 'Русский',
        'en' => 'English',
    ],
];
```

## Лицензия

MIT
