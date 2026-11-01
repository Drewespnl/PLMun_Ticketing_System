
document.querySelectorAll('.toggle-password').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.target);
        const icon = button.querySelector('i');

        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';

        icon.classList.toggle('fa-eye', !show);
        icon.classList.toggle('fa-eye-slash', show);

        button.setAttribute(
            'aria-label',
            show ? 'Hide password' : 'Show password'
        );
    });
});

// Registration only: check whether passwords match.
const password = document.getElementById('password');
const confirmPassword = document.getElementById('confirm_password');
const matchMessage = document.getElementById('password-match-message');

function checkPasswordMatch() {
    if (!password || !confirmPassword || !matchMessage) return;

    if (!confirmPassword.value) {
        matchMessage.textContent = '';
    } else if (password.value === confirmPassword.value) {
        matchMessage.textContent = 'Passwords match.';
        matchMessage.style.color = 'green';
    } else {
        matchMessage.textContent = 'Passwords do not match.';
        matchMessage.style.color = 'red';
    }
}

password?.addEventListener('input', checkPasswordMatch);
confirmPassword?.addEventListener('input', checkPasswordMatch);
