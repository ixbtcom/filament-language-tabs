# EditorJS Locale Rendering Fix

## Что происходит
- В `LanguageTabs` каждая локаль предоставляет своё состояние в виде JSON (builder → `content.en`, `content.ru`, …).
- Простые поля обновляются корректно, а `EditorJs` после переключения вкладки показывает «пустой редактор», даже если в выбранной локали блоки есть.
- В локалях без контента теперь действительно пусто, что подтверждает, что PHP-часть (`prepareBuilderForLocale`) отдаёт правильное состояние. Проблема только в том, что уже инициализированный EditorJS не перерисовывается под новое значение.

## Причина
- Компонент Alpine (`resources/js/editorjs.js`) создаёт EditorJS один раз в `init()`, передавая текущее состояние из `$wire.entangle('statePath')`.
- Когда Livewire меняет значение (например, при переключении локалей), `entangle` обновляет `state`, но дальнейших действий нет. Из-за `wire:ignore` DOM не переотрисовывается, и EditorJS остаётся со старыми блоками. При этом в `LanguageTabs` состояние может обнуляться, поэтому редактор чистится, но обратно новые блоки не рендерятся.
- Отсюда ощущение «EditorJS может быть только один» – на странице несколько экземпляров, но каждый из них заморожен на первом значении.

## Рекомендованное исправление
1. **Следить за изменениями state**  
   В `editorjsComponent.init()`:
   ```js
   return {
       instance: null,
       state,
       isUpdatingFromEditor: false,
       // …
       init() {
           // … существующая инициализация EditorJS …

           this.$watch('state', async (newValue, oldValue) => {
               if (this.isUpdatingFromEditor) {
                   return;
               }

               const data = this.normalizeEditorData(newValue);
               const prev = this.normalizeEditorData(oldValue);
               if (JSON.stringify(data) === JSON.stringify(prev)) {
                   return;
               }

               await this.instance?.isReady;

               if (!data.blocks?.length) {
                   await this.instance?.clear();
                   return;
               }

               await this.instance?.render(data);
           });
       },
       normalizeEditorData(value) {
           if (!value || !Array.isArray(value.blocks)) {
               return { blocks: [] };
           }

           return {
               ...value,
               blocks: value.blocks.filter(Boolean),
           };
       },
   };
   ```

2. **Флаг, подавляющий рекурсию**  
   При сохранении в `onChange`:
   ```js
   onChange: async () => {
       this.isUpdatingFromEditor = true;
       const output = await this.instance.save();
       this.state = this.normalizeEditorData(output);
       this.$nextTick(() => { this.isUpdatingFromEditor = false; });
   },
   ```
   Так watcher не срабатывает повторно на собственные изменения.

3. **Нормализация входных данных**  
   EditorJS ожидает объект с `blocks`. При `null`/`[]` нужно отдавать `{ blocks: [] }`, иначе `render()` падает. Для пустой локали просто вызываем `instance.clear()`.

4. **Проверить множественные инстансы**  
   Убедиться, что при открытой странице с несколькими EditorJS (несколько локалей, либо другие поля) редакторы синхронно получают свои данные. При необходимости добавить `this.instance?.destroy?.()` в `beforeDestroy` (если используется Alpine v3 `x-effect`) — сейчас не обязательно, потому что каждый holder уникален.

## Проверка после правок
1. Переключение локали с существующими блоками должно моментально показывать контент без обновления страницы.
2. Изменение контента в одной локали не должно «протекать» в другие локали.
3. Несколько EditorJS на одной форме (например, два поля) работают независимо.
4. Валидация и сохранение по-прежнему возвращают JSON с блоками.

После внедрения этих изменений Alpine-обёртка будет синхронизировать EditorJS с состоянием Livewire, и редактор перестанет «застревать» на первом значении.
