<div
    @languagetabchanged="
        console.log('[LanguageTabs] Event caught, locale:', $event.detail.locale);

        // Ждем пока Alpine обновит видимость табов
        setTimeout(() => {
            // Находим видимый таб
            const visibleTab = document.querySelector('[role=tabpanel]:not([style*=\"display: none\"])');
            if (!visibleTab) {
                console.log('[LanguageTabs] No visible tab found');
                return;
            }

            // Находим EditorJS в этом табе
            const editorElements = visibleTab.querySelectorAll('[x-data*=\"editorjs\"]');
            console.log('[LanguageTabs] Found EditorJS:', editorElements.length);

            editorElements.forEach(editorEl => {
                const alpineData = Alpine.\$data(editorEl);
                if (!alpineData || !alpineData.instance) return;

                console.log('[LanguageTabs] Current state:', alpineData.state);
                console.log('[LanguageTabs] StatePath:', alpineData.statePath);

                // Ключевой момент: перечитываем данные из Livewire
                const livewireEl = editorEl.closest('[wire\\:id]');
                if (livewireEl && livewireEl.__livewire) {
                    const freshData = livewireEl.__livewire.get(alpineData.statePath);
                    console.log('[LanguageTabs] Fresh data from Livewire:', freshData);

                    // Обновляем Alpine state
                    alpineData.state = freshData || { blocks: [] };
                }

                // Рендерим EditorJS с обновленными данными
                alpineData.instance.render(alpineData.state).catch(console.error);
            });
        }, 100);
    "
>
    {{$getChildComponentContainer()}}
</div>



