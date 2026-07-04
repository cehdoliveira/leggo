document.addEventListener('alpine:init', () => {
    Alpine.data('profilesController', () => ({
        editData: { idx: 0, name: '', slug: '', parent: 0 },
        _editModal: null,
        _createModal: null,

        init() {
            this._editModal = new bootstrap.Modal(document.getElementById('editProfileModal'));
            this._createModal = new bootstrap.Modal(document.getElementById('createProfileModal'));
        },

        openCreate() {
            this._createModal.show();
        },

        openEdit(idx, name, slug, parent) {
            this.editData = { idx: idx, name: name, slug: slug, parent: parent };
            this._editModal.show();
        },

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
