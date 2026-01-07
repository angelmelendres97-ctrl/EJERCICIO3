<div
    x-data
    x-init="
        document.addEventListener('livewire:init', () => {
            Livewire.on('open-orden-compra-print', (payload) => {
                const url = payload?.url;
                if (!url) {
                    return;
                }

                window.open(url, '_blank');
            });
        });
    "
></div>
