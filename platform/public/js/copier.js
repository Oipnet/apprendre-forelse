// Boutons « Copier » de l'espace des chefs de cohorte (code et lien d'invitation) : un fichier plutôt qu'un
// script dans la page, que la politique de sécurité du contenu n'autorise pas.
document.querySelectorAll('[data-copy]').forEach((button) => {
    if (!navigator.clipboard) {
        return;
    }
    button.hidden = false;
    button.addEventListener('click', async () => {
        await navigator.clipboard.writeText(button.dataset.copy);
        button.textContent = 'Copié ✓';
        setTimeout(() => { button.textContent = 'Copier'; }, 2000);
    });
});
