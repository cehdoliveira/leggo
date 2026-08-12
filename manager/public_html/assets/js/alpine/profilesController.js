document.addEventListener('alpine:init', () => {
    Alpine.data('profilesController', () => ({
        async confirmRemove(form, profileName) {
            const result = await Swal.fire({
                title: 'Remover perfil?',
                html: `O perfil <strong>${profileName}</strong> será removido. Esta ação não pode ser desfeita.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remover',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#ef4444',
            });
            if (result.isConfirmed) form.submit();
        },
    }));
});
