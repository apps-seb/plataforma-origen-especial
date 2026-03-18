document.addEventListener('DOMContentLoaded', () => {

    // Control de Pestañas
    const tabs = document.querySelectorAll('.origen-tab-btn');
    const forms = document.querySelectorAll('.origen-form');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            forms.forEach(f => f.style.display = 'none');

            tab.classList.add('active');
            document.getElementById(`origen-${tab.dataset.target}-form`).style.display = 'block';
            hideMsg();
        });
    });

    const msgBox = document.getElementById('origen-msg');

    function showMsg(text, type) {
        if(!msgBox) return;
        msgBox.textContent = text;
        msgBox.className = `origen-msg ${type}`;
    }

    function hideMsg() {
        if(!msgBox) return;
        msgBox.className = 'origen-msg';
        msgBox.textContent = '';
    }

    function handleAjaxRequest(action, dataObj) {
        showMsg('Procesando...', 'loading');

        const formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('nonce', origenApp.nonce);

        for (const key in dataObj) {
            formData.append(key, dataObj[key]);
        }

        fetch(origenApp.ajax_url, {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                showMsg(res.data, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showMsg(res.data, 'error');
            }
        })
        .catch(err => {
            showMsg('Error de conexión', 'error');
        });
    }

    // Submit Login
    const loginForm = document.getElementById('origen-login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = {
                user: document.getElementById('log_user').value,
                pass: document.getElementById('log_pass').value
            };
            handleAjaxRequest('origen_login_action', data);
        });
    }

    // Submit Register
    const registerForm = document.getElementById('origen-register-form');
    if (registerForm) {
        registerForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = {
                name: document.getElementById('reg_name').value,
                email: document.getElementById('reg_email').value,
                pass: document.getElementById('reg_pass').value
            };
            handleAjaxRequest('origen_register_action', data);
        });
    }

    // Toggle Mi Finca View
    const btnMiFinca = document.getElementById('origen-btn-mi-finca');
    const btnVolver = document.getElementById('origen-btn-volver');
    const dashboardContainer = document.getElementById('origen-dashboard-container');
    const fincaContainer = document.getElementById('origen-finca-container');

    if (btnMiFinca && dashboardContainer && fincaContainer) {
        btnMiFinca.addEventListener('click', () => {
            dashboardContainer.style.display = 'none';
            fincaContainer.style.display = 'block';
        });
    }

    if (btnVolver && dashboardContainer && fincaContainer) {
        btnVolver.addEventListener('click', () => {
            fincaContainer.style.display = 'none';
            dashboardContainer.style.display = 'block';
        });
    }

    // Submit Finca
    const fincaForm = document.getElementById('origen-finca-form');
    if (fincaForm) {
        fincaForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const msgBoxFinca = document.getElementById('origen-finca-msg');
            if(msgBoxFinca) {
                msgBoxFinca.textContent = 'Guardando...';
                msgBoxFinca.className = 'origen-msg loading';
            }

            const formData = new URLSearchParams();
            formData.append('action', 'origen_save_finca');
            formData.append('nonce', origenApp.nonce);

            formData.append('nombre_finca', document.getElementById('finca_nombre').value);
            formData.append('ubicacion', document.getElementById('finca_ubicacion').value);
            formData.append('altura', document.getElementById('finca_altura').value);
            formData.append('hectareas', document.getElementById('finca_hectareas').value);
            formData.append('cantidad_plantas', document.getElementById('finca_plantas').value);
            formData.append('variedad_cafe', document.getElementById('finca_variedad').value);
            formData.append('edad_cultivo', document.getElementById('finca_edad').value);
            formData.append('densidad_siembra', document.getElementById('finca_densidad').value);
            formData.append('tipo_sombra', document.querySelector('input[name="finca_sombra"]:checked').value);
            formData.append('sistema_cultivo', document.querySelector('input[name="finca_sistema"]:checked').value);

            fetch(origenApp.ajax_url, {
                method: 'POST',
                body: formData,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    if(msgBoxFinca) {
                        msgBoxFinca.textContent = res.data;
                        msgBoxFinca.className = 'origen-msg success';
                    }
                } else {
                    if(msgBoxFinca) {
                        msgBoxFinca.textContent = res.data;
                        msgBoxFinca.className = 'origen-msg error';
                    }
                }
            })
            .catch(err => {
                if(msgBoxFinca) {
                    msgBoxFinca.textContent = 'Error de conexión';
                    msgBoxFinca.className = 'origen-msg error';
                }
            });
        });
    }
});