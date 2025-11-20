// Konfiguration
const API_BASE_URL = 'https://smv-antrage.onrender.com'; // Passe diese URL bei Bedarf an

// DOM-Elemente
const views = document.querySelectorAll('.view');
const navLinks = document.querySelectorAll('nav a');
const homeLink = document.getElementById('home-link');
const loginLink = document.getElementById('login-link');
const antragLink = document.getElementById('antrag-link');
const dashboardLink = document.getElementById('dashboard-link');
const adminLink = document.getElementById('admin-link');
const logoutLink = document.getElementById('logout-link');

const loginForm = document.getElementById('login-form');
const antragForm = document.getElementById('antrag-form');
const updateForm = document.getElementById('update-form');
const createUserForm = document.getElementById('create-user-form');
const addTagForm = document.getElementById('add-tag-form');
const deleteAntragBtn = document.getElementById('delete-antrag-btn');

const antragTabelleBody = document.querySelector('#antrag-tabelle tbody');
const filterStatusSelect = document.getElementById('filter-status');
const filterPhaseSelect = document.getElementById('filter-phase');
const filterTagSelect = document.getElementById('filter-tag');

const benachrichtigungCheckbox = document.getElementById('benachrichtigung');
const benachrichtigungArtGroup = document.getElementById('benachrichtigungs-art-group');

let currentUserId = null;
let currentIsAdmin = false;
let currentUserRole = null;

// Hilfsfunktionen
function showView(id) {
    views.forEach(view => view.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

function updateNavigation() {
    const token = localStorage.getItem('access_token');
    if (token) {
        document.getElementById('login-nav-item').style.display = 'none';
        document.getElementById('dashboard-nav-item').style.display = 'block';
        document.getElementById('logout-nav-item').style.display = 'block';
        
        // Navigation basierend auf Rolle anzeigen
        if (currentUserRole === 'admin') {
            document.getElementById('admin-nav-item').style.display = 'block';
        } else {
            document.getElementById('admin-nav-item').style.display = 'none';
        }
        
        document.getElementById('username-display').textContent = localStorage.getItem('username') || '';
    } else {
        document.getElementById('login-nav-item').style.display = 'block';
        document.getElementById('dashboard-nav-item').style.display = 'none';
        document.getElementById('admin-nav-item').style.display = 'none';
        document.getElementById('logout-nav-item').style.display = 'none';
    }
}

async function fetchWithAuth(endpoint, options = {}) {
    const token = localStorage.getItem('access_token');
    const headers = options.headers || {};
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }
    const response = await fetch(`${API_BASE_URL}${endpoint}`, { ...options, headers });
    if (response.status === 401 || response.status === 403) {
        logout();
        throw new Error("Nicht autorisiert. Bitte neu anmelden.");
    }
    return response;
}

function formatDate(dateString) {
    const options = { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    return new Date(dateString).toLocaleDateString('de-DE', options);
}

// Ansichts-Wechsel
homeLink.addEventListener('click', (e) => {
    e.preventDefault();
    showView('home-view');
});

antragLink.addEventListener('click', (e) => {
    e.preventDefault();
    showView('antrag-view');
});

loginLink.addEventListener('click', (e) => {
    e.preventDefault();
    showView('login-view');
});

dashboardLink.addEventListener('click', async (e) => {
    e.preventDefault();
    showView('dashboard-view');
    await fetchAntraege();
    await fetchFilterOptions();
});

adminLink.addEventListener('click', async (e) => {
    e.preventDefault();
    showView('admin-view');
    await loadTagsList();
});

logoutLink.addEventListener('click', (e) => {
    e.preventDefault();
    logout();
});

// Antrag einreichen
antragForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    // Feldnamen korrigieren für API
    data.begründung = data.begruendung;
    delete data.begruendung;
    delete data.benachrichtigung; // Das wird durch benachrichtigung_gewünscht ersetzt
    
    data.benachrichtigung_gewünscht = form.elements['benachrichtigung'].checked;
    
    // API erwartet `true` oder `false` als Boolean
    data.benachrichtigung_gewünscht = data.benachrichtigung_gewünscht ? true : false;

    // Optionales Feld benachrichtigungs_art nur senden, wenn benachrichtigung_gewünscht true ist
    if (!data.benachrichtigung_gewünscht) {
        delete data.benachrichtigungs_art;
    }
    
    console.log('Sending data:', data); // Debug-Log
    
    try {
        const response = await fetch(`${API_BASE_URL}/antraege`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
        });

        const result = await response.json();
        const successMessage = document.getElementById('antrag-success');
        const errorMessage = document.getElementById('antrag-error');
        
        console.log('API response:', response.status, result); // Debug-Log
        
        if (response.ok) {
            successMessage.textContent = 'Antrag erfolgreich eingereicht!';
            successMessage.style.display = 'block';
            errorMessage.style.display = 'none';
            form.reset();
        } else {
            console.error('API error details:', result); // Debug-Log
            errorMessage.textContent = result.detail || 'Fehler beim Einreichen des Antrags.';
            errorMessage.style.display = 'block';
            successMessage.style.display = 'none';
        }
    } catch (error) {
        console.error('Network error:', error); // Debug-Log
        document.getElementById('antrag-error').textContent = 'Netzwerkfehler: ' + error.message;
        document.getElementById('antrag-error').style.display = 'block';
    }
});

// Event-Listener für Checkbox, um Benachrichtigungsart anzuzeigen/verstecken
benachrichtigungCheckbox.addEventListener('change', (e) => {
    benachrichtigungArtGroup.style.display = e.target.checked ? 'block' : 'none';
});

// Login
loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = e.target.username.value;
    const password = e.target.password.value;
    const errorMessage = document.getElementById('login-error');

    try {
        const response = await fetch(`${API_BASE_URL}/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ benutzername: username, passwort: password }),
        });
        const result = await response.json();
        if (response.ok) {
            localStorage.setItem('access_token', result.access_token);
            localStorage.setItem('username', username);
            await fetchAndStoreUserInfo();
            showView('dashboard-view');
            await fetchAntraege();
            await fetchFilterOptions();
            errorMessage.style.display = 'none';
        } else {
            errorMessage.textContent = result.detail || 'Falscher Benutzername oder Passwort.';
            errorMessage.style.display = 'block';
        }
    } catch (error) {
        errorMessage.textContent = 'Netzwerkfehler: ' + error.message;
        errorMessage.style.display = 'block';
    }
});

async function fetchAndStoreUserInfo() {
    try {
        const response = await fetchWithAuth('/me');
        const user = await response.json();
        currentUserId = user.id;
        currentIsAdmin = user.ist_admin;
        currentUserRole = user.rolle;
        localStorage.setItem('is_admin', user.ist_admin);
        localStorage.setItem('user_role', user.rolle);
        updateNavigation();
    } catch (error) {
        console.error("Fehler beim Abrufen der Benutzerinfo:", error);
        logout();
    }
}

// Logout
function logout() {
    localStorage.removeItem('access_token');
    localStorage.removeItem('username');
    localStorage.removeItem('is_admin');
    localStorage.removeItem('user_role');
    currentUserId = null;
    currentIsAdmin = false;
    currentUserRole = null;
    updateNavigation();
    showView('home-view');
}

// Dashboard
async function fetchAntraege() {
    antragTabelleBody.innerHTML = '';
    const dashboardMessage = document.getElementById('dashboard-message');
    dashboardMessage.textContent = 'Lade Anträge...';
    dashboardMessage.style.display = 'block';

    const status = filterStatusSelect.value;
    const phase = filterPhaseSelect.value;
    const tag = filterTagSelect.value;

    const queryParams = new URLSearchParams();
    if (status) queryParams.append('status', status);
    if (phase) queryParams.append('phase', phase);
    if (tag) queryParams.append('tag', tag);
    
    try {
        const response = await fetchWithAuth(`/antraege?${queryParams.toString()}`);
        const antraege = await response.json();

        console.log('Received antraege:', antraege); // Debug-Log

        if (antraege.length === 0) {
            dashboardMessage.textContent = 'Keine Anträge gefunden.';
            return;
        }

        dashboardMessage.style.display = 'none';

        antraege.forEach(antrag => {
            const row = document.createElement('tr');
            const antragId = antrag._id || antrag.id; // MongoDB verwendet _id
            console.log('Processing antrag:', antrag, 'ID:', antragId); // Debug-Log
            row.innerHTML = `
                <td>${antragId != null ? antragId : 'N/A'}</td>
                <td>${antrag.thema || 'N/A'}</td>
                <td>${antrag.lerngruppe || 'N/A'}</td>
                <td>${antrag.status || 'N/A'}</td>
                <td>${(antrag.tags || []).join(', ')}</td>
                <td>${antrag.erstellt_am ? formatDate(antrag.erstellt_am) : 'N/A'}</td>
                <td><button onclick="showAntragDetails('${antragId}')" ${antragId == null ? 'disabled' : ''}>Details</button></td>
            `;
            antragTabelleBody.appendChild(row);
        });

    } catch (error) {
        dashboardMessage.textContent = error.message;
    }
}

async function fetchFilterOptions() {
    try {
        const statsResponse = await fetchWithAuth('/statistiken');
        const stats = await statsResponse.json();

        const statusOptions = Object.keys(stats.status_verteilung);
        filterStatusSelect.innerHTML = '<option value="">Alle Status</option>';
        statusOptions.forEach(status => {
            const option = document.createElement('option');
            option.value = status;
            option.textContent = status;
            filterStatusSelect.appendChild(option);
        });

        const phaseOptions = Object.keys(stats.phase_verteilung);
        filterPhaseSelect.innerHTML = '<option value="">Alle Phasen</option>';
        phaseOptions.forEach(phase => {
            const option = document.createElement('option');
            option.value = phase;
            option.textContent = phase;
            filterPhaseSelect.appendChild(option);
        });
        
        const tagOptions = stats.verfügbare_tags;
        filterTagSelect.innerHTML = '<option value="">Alle Tags</option>';
        tagOptions.forEach(tag => {
            const option = document.createElement('option');
            option.value = tag;
            option.textContent = tag;
            filterTagSelect.appendChild(option);
        });

    } catch (error) {
        console.error("Fehler beim Laden der Filter-Optionen:", error);
    }
}

filterStatusSelect.addEventListener('change', fetchAntraege);
filterPhaseSelect.addEventListener('change', fetchAntraege);
filterTagSelect.addEventListener('change', fetchAntraege);

// Antragsdetails
// ... (Alles wie gehabt bis zu showAntragDetails)

window.showAntragDetails = async (antragId) => {
    console.log('showAntragDetails called with ID:', antragId); // Debug-Log

    // Grundsätzlich alles ausblenden/deaktivieren
    const updateFormEl = document.getElementById('update-form');
    const deleteBtnEl = document.getElementById('delete-antrag-btn');
    const newStatusEl = document.getElementById('new-status');
    const newTagsEl = document.getElementById('new-tags');
    const updateSubmitBtnEl = document.getElementById('update-submit-btn');

    if (updateFormEl) updateFormEl.style.display = 'none';
    if (deleteBtnEl) deleteBtnEl.style.display = 'none';
    if (newStatusEl) newStatusEl.disabled = true;
    if (newTagsEl) newTagsEl.disabled = true;
    if (updateSubmitBtnEl) updateSubmitBtnEl.disabled = true;

    if (!antragId || antragId === 'undefined') {
        alert('Fehler: Antrag-ID ist nicht verfügbar.');
        return;
    }

    try {
        const response = await fetchWithAuth(`/antraege/${antragId}`);
        const antrag = await response.json();

        console.log('Received antrag details:', antrag); // Debug-Log

        document.getElementById('details-title').textContent = antrag.thema;
        document.getElementById('details-name').textContent = `${antrag.vorname} ${antrag.nachname}`;
        document.getElementById('details-lerngruppe').textContent = antrag.lerngruppe;
        document.getElementById('details-thema').textContent = antrag.thema;
        document.getElementById('details-begruendung').textContent = antrag.begründung;
        document.getElementById('details-phase').textContent = antrag.phase;
        document.getElementById('details-status').textContent = antrag.status;
        document.getElementById('details-tags').textContent = (antrag.tags || []).join(', ') || 'Keine Tags';
        document.getElementById('details-erstellt').textContent = formatDate(antrag.erstellt_am);


        // Update-Formular vorbereiten - verwende _id oder id
        const actualId = antrag._id || antrag.id;
        if (updateFormEl) updateFormEl.dataset.antragId = actualId;
        if (deleteBtnEl) deleteBtnEl.dataset.antragId = actualId;
        if (newStatusEl) newStatusEl.value = antrag.status || '';
        if (newTagsEl) newTagsEl.value = (antrag.tags || []).join(', ');

        // Admin-Funktionen anzeigen (nur für Admins)
        if (deleteBtnEl) {
            if (currentUserRole === 'admin') {
                deleteBtnEl.style.display = 'inline-block';
            } else {
                deleteBtnEl.style.display = 'none';
            }
        }

        // Bearbeitungsformular anzeigen (für Admins und Schülersprecher)
        const updateSection = document.querySelector('#antrag-details-view h3');
        const updateFormElement = document.getElementById('update-form');
        if (updateSection) {
            if (currentUserRole === 'admin' || currentUserRole === 'schuelersprecher') {
                updateSection.style.display = 'block';
                if (updateFormElement) updateFormElement.style.display = 'block';
            } else {
                updateSection.style.display = 'none';
                if (updateFormElement) updateFormElement.style.display = 'none';
            }
        }

        showView('antrag-details-view');
    } catch (error) {
        alert(error.message);
        showView('dashboard-view');
    }
};

// Antrag aktualisieren
updateForm.addEventListener('submit', async (e) => {
    // Admin-Prüfung im Frontend
    if (!currentIsAdmin) {
        alert('Nur Admins dürfen Anträge bearbeiten.');
        return;
    }
    e.preventDefault();
    const antragId = e.target.dataset.antragId;
    const newStatus = document.getElementById('new-status').value;
    const newTags = document.getElementById('new-tags').value.split(',').map(tag => tag.trim()).filter(tag => tag.length > 0);
    const updateSuccess = document.getElementById('update-success');
    const updateError = document.getElementById('update-error');

    try {
        const response = await fetchWithAuth(`/antraege/${antragId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: newStatus, tags: newTags }),
        });
        const result = await response.json();
        if (response.ok) {
            updateSuccess.textContent = 'Antrag erfolgreich aktualisiert!';
            updateSuccess.style.display = 'block';
            updateError.style.display = 'none';
            // Aktualisierte Daten anzeigen
            document.getElementById('details-status').textContent = result.status;
            document.getElementById('details-tags').textContent = (result.tags || []).join(', ') || 'Keine Tags';
        } else {
            updateError.textContent = result.detail || 'Fehler beim Aktualisieren des Antrags.';
            updateError.style.display = 'block';
            updateSuccess.style.display = 'none';
        }
    } catch (error) {
        updateError.textContent = error.message;
        updateError.style.display = 'block';
    }
});

// Antrag löschen
deleteAntragBtn.addEventListener('click', async () => {
    // Admin-Prüfung im Frontend
    if (!currentIsAdmin) {
        alert('Nur Admins dürfen Anträge löschen.');
        return;
    }
    const antragId = deleteAntragBtn.dataset.antragId;
    if (confirm("Sind Sie sicher, dass Sie diesen Antrag löschen möchten?")) {
        const deleteMessage = document.getElementById('delete-antrag-message');
        try {
            const response = await fetchWithAuth(`/antraege/${antragId}`, { method: 'DELETE' });
            const result = await response.json();
            if (response.ok) {
                alert(result.message);
                showView('dashboard-view');
                fetchAntraege(); // Tabelle neu laden
            } else {
                deleteMessage.textContent = result.detail || 'Fehler beim Löschen des Antrags.';
                deleteMessage.style.display = 'block';
            }
        } catch (error) {
            deleteMessage.textContent = error.message;
            deleteMessage.style.display = 'block';
        }
    }
});

// ... (Rest des Skripts bleibt unverändert)

// Admin-Bereich: Benutzer erstellen
createUserForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = document.getElementById('new-username').value;
    const password = document.getElementById('new-password').value;
    const rolle = document.getElementById('user-rolle').value;
    const messageElement = document.getElementById('create-user-message');

    try {
        const response = await fetchWithAuth('/benutzer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ benutzername: username, passwort: password, rolle: rolle }),
        });
        const result = await response.json();
        
        console.log('API Response:', result); // Debug-Log
        
        if (response.ok) {
            const displayName = result.benutzername || username || 'Unbekannt';
            messageElement.textContent = `Benutzer '${displayName}' erfolgreich erstellt.`;
            messageElement.style.display = 'block';
            messageElement.classList.remove('error-message');
            messageElement.classList.add('success-message');
            createUserForm.reset();
        } else {
            messageElement.textContent = result.detail || result.message || 'Fehler beim Erstellen des Benutzers.';
            messageElement.style.display = 'block';
            messageElement.classList.remove('success-message');
            messageElement.classList.add('error-message');
        }
    } catch (error) {
        console.error('Error creating user:', error); // Debug-Log
        messageElement.textContent = 'Netzwerkfehler: ' + error.message;
        messageElement.style.display = 'block';
        messageElement.classList.remove('success-message');
        messageElement.classList.add('error-message');
    }
});

// Admin-Bereich: Tags verwalten
async function loadTagsList() {
    const tagsList = document.getElementById('tags-list');
    tagsList.innerHTML = '';
    const messageElement = document.getElementById('admin-message');
    
    try {
        const response = await fetchWithAuth('/tags');
        const tags = await response.json();
        
        if (tags.length === 0) {
            tagsList.innerHTML = '<li>Keine Tags vorhanden.</li>';
            return;
        }

        tags.forEach(tag => {
            const li = document.createElement('li');
            li.innerHTML = `
                <span>${tag}</span>
                <button class="delete-tag-btn" data-tag-name="${tag}">&times;</button>
            `;
            tagsList.appendChild(li);
        });

        // Event-Listener für Löschen-Buttons hinzufügen
        tagsList.querySelectorAll('.delete-tag-btn').forEach(button => {
            button.addEventListener('click', async (e) => {
                const tagName = e.target.dataset.tagName;
                await deleteTag(tagName);
            });
        });

    } catch (error) {
        messageElement.textContent = error.message;
        messageElement.style.display = 'block';
    }
}

async function deleteTag(tagName) {
    const messageElement = document.getElementById('add-tag-message');
    if (confirm(`Sind Sie sicher, dass Sie den Tag '${tagName}' löschen möchten?`)) {
        try {
            const response = await fetchWithAuth(`/tags/${tagName}`, { method: 'DELETE' });
            const result = await response.json();
            if (response.ok) {
                messageElement.textContent = result.message;
                messageElement.style.display = 'block';
                messageElement.classList.remove('error-message');
                messageElement.classList.add('success-message');
                await loadTagsList(); // Tags-Liste neu laden
            } else {
                messageElement.textContent = result.message || 'Fehler beim Löschen des Tags.';
                messageElement.style.display = 'block';
                messageElement.classList.remove('success-message');
                messageElement.classList.add('error-message');
            }
        } catch (error) {
            messageElement.textContent = error.message;
            messageElement.style.display = 'block';
            messageElement.classList.remove('success-message');
            messageElement.classList.add('error-message');
        }
    }
}

addTagForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const tagName = document.getElementById('new-tag-name').value;
    const messageElement = document.getElementById('add-tag-message');

    try {
        const response = await fetchWithAuth(`/tags?tag_name=${tagName}`, { method: 'POST' });
        const result = await response.json();
        if (response.ok) {
            messageElement.textContent = result.message;
            messageElement.style.display = 'block';
            messageElement.classList.remove('error-message');
            messageElement.classList.add('success-message');
            addTagForm.reset();
            await loadTagsList(); // Tags-Liste neu laden
        } else {
            messageElement.textContent = result.detail || 'Fehler beim Hinzufügen des Tags.';
            messageElement.style.display = 'block';
            messageElement.classList.remove('success-message');
            messageElement.classList.add('error-message');
        }
    } catch (error) {
        messageElement.textContent = error.message;
        messageElement.style.display = 'block';
        messageElement.classList.remove('success-message');
        messageElement.classList.add('error-message');
    }
});

// Initialisierung
async function init() {
    const token = localStorage.getItem('access_token');
    if (token) {
        // Lade gespeicherte Benutzerrolle
        currentUserRole = localStorage.getItem('user_role');
        currentIsAdmin = localStorage.getItem('is_admin') === 'true';
        
        await fetchAndStoreUserInfo();
        showView('dashboard-view');
        await fetchAntraege();
        await fetchFilterOptions();
    } else {
        showView('antrag-view');
    }
}

init();
