// Global variables
let topicsData = [];
let formTypesData = {};
let targetsData = {};
let currentStep = 0;
const totalSteps = document.querySelectorAll(".generator>section").length;

function showStep(stepNumber) {
    document.querySelectorAll('.generator>section').forEach(section => {
        section.classList.remove('active');
    });

    const currentSection = document.getElementById(`step-${stepNumber}`);
    if (currentSection) {
        currentSection.classList.add('active');
    }

    document.querySelector('.generator>header>span').textContent = `Krok ${stepNumber + 1} z ${totalSteps}`;
    if (stepNumber + 1 == totalSteps) {
        document.querySelector('.generator>header>a').style.display = 'none';
    } else if (stepNumber > 0) {
        document.querySelector('.generator>header>a').style.display = 'block';
    } else {
        document.querySelector('.generator>header>a').style.display = 'none';
    }

    currentStep = stepNumber;
}

function prevStep() {
    if (currentStep > 0) {
        showStep(currentStep - 1);
    }
}

function nextStep() {
    if (currentStep < totalSteps - 1) {
        // Validate current step before proceeding
        if (validateCurrentStep()) {
            showStep(currentStep + 1);
        }
    }
}

function validateCurrentStep() {
    switch (currentStep) {
        case 0:
            return true; // No validation needed for intro step
        case 1:
            return validateTopics();
        case 2:
            return renderTargets();
        case 3:
            return validateForm();
        default:
            return true;
    }
}

function validateForm() {
    const formType = document.querySelector('input[name="formType"]:checked')?.value;
    const target = document.querySelector('input[name="target"]:checked')?.value;

    if (!formType) {
        alert('Proszę wybrać typ pisma');
        setButtonState(3, false)
        return false;
    }

    if (!target) {
        alert('Proszę wybrać adresata');
        setButtonState(3, true)
        return false;
    }

    return true;
}

function restartWizard() {
    // Reset form
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.querySelectorAll('input[name="formType"]').forEach(radio => radio.checked = false);
    document.querySelectorAll('input[name="target"]').forEach(radio => radio.checked = false);
    document.getElementById('output').textContent = '';
    document.getElementById('status').textContent = 'Gotowy do generowania';

    document.getElementById('mailtoButton').href = '#';
    document.getElementById('mailtoButton').style.display = 'none';
    document.getElementById('gmailButton').href = '#';
    document.getElementById('gmailButton').style.display = 'none';

    document.querySelector("section#step-5>h1").innerHTML = 'Generowanie pisma';

    // Go back to step 1 (skip intro)
    showStep(1);
}

document.addEventListener('DOMContentLoaded', async () => {
    const generatorContainer = document.querySelectorAll('.generator');
    if (generatorContainer.length === 0) return;


    try {
        showStep(0);
        // Load topics
        const topicsResponse = await fetch('/generator/topics');
        topicsData = await topicsResponse.json();
        renderTopics();

        // Load form types
        const formTypesResponse = await fetch('/generator/form_types');
        formTypesData = await formTypesResponse.json();
        renderFormTypes();

        // Load targets
        const targetsResponse = await fetch('/generator/targets');
        targetsData = await targetsResponse.json();

        // Add event listeners for navigation buttons
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('disabled')) {
                return false;
            } else if (e.target.dataset.action === 'next') {
                nextStep();
            } else if (e.target.dataset.action === 'prev') {
                prevStep();
            } else if (e.target.dataset.action === 'restart') {
                restartWizard();
            } else if (e.target.dataset.action === 'generate') {
                generate();
            }
        });
    } catch (error) {
        console.error('Error initializing data:', error);
        alert('Wystąpił błąd podczas ładowania danych. Odśwież stronę i spróbuj ponownie.');
    }
});

// Render topics checkboxes
function renderTopics() {
    const container = document.getElementById('fs-topics');

    container.innerHTML = Object.entries(topicsData).map(([id, topic]) => `
            <label>
              <input type="checkbox" name='topics[]' value="${id}" onchange="window.validateTopics()">
              ${topic.title}
            </label>
        `).join('');
}

// Render form types radio buttons
function renderFormTypes() {
    const container = document.getElementById('fs-types');

    container.innerHTML = Object.entries(formTypesData).map(([id, data], index) => `
            <label>
              <input type="radio" name="formType" value="${id}" onchange="window.renderTargets()" ${index === 0 ? 'checked' : ''}>
              ${data || id}
            </label>
        `).join('');
}

// Render targets based on selected form type
const renderTargets = () => {
    const formType = document.querySelector('input[name="formType"]:checked')?.value;
    const container = document.getElementById('fs-targets');

    container.innerHTML = '';

    if (!formType) {
        container.innerHTML = '<p>Najpierw wybierz typ pisma</p>';
        return;
    }

    // Filter targets that support the selected form type and have recipient defined
    const availableTargets = Object.entries(targetsData)
        .filter(([_, target]) => target.forms.includes(formType))
        .filter(([_, target]) => target.recipient)   // @FIXME implement proper recipient selection in separate for those cases
        .sort((a, b) => a[1].title.localeCompare(b[1].title));

    container.innerHTML = availableTargets.map(([id, target]) => `
            <label>
              <input type="radio" name="target" data-recipient="${target.recipient || ''}" onchange="window.checkRecipient()" value="${id}">
              ${id}
            </label>
        `).join('');

    return true;
}

window.renderTargets = renderTargets;

// check recipient and possible next steps
const checkRecipient = () => {
    const selectedTarget = document.querySelector('input[name="target"]:checked');
    const selectedTargetValue = selectedTarget?.value;
    const selectedTargetRecipient = selectedTarget?.dataset?.recipient;
    const actionButton = document.querySelector('.generator>section#step-3>nav>a');

    if (selectedTargetRecipient) {    // we know who is the recipient
        actionButton.innerHTML = 'Generuj';
        actionButton.dataset.action = 'generate';
        actionButton.disabled = false;
        actionButton.classList.remove('disabled');
    } else if (selectedTargetValue) { // we need to perform additional selection
        actionButton.innerHTML = 'Dalej';
        actionButton.dataset.action = 'next';
        actionButton.disabled = false;
        actionButton.classList.remove('disabled');
    } else {                          // fallback
        actionButton.disabled = true;
        actionButton.classList.add('disabled');
    }

    return true;
}

window.checkRecipient = checkRecipient;

function setButtonState(step, enable) {
    const buttons = document.querySelectorAll(`#step-${step} a.button`);
    if (enable)
        return buttons.removeClass('disabled')
    buttons.addClass('disabled')
}

// Validate that 1-3 topics are selected
const validateTopics = () => {
    const selectedTopics = document.querySelectorAll('fieldset#fs-topics input[type="checkbox"]:checked');
    const errorElement = document.getElementById('topicError');

    if (selectedTopics.length === 0 || selectedTopics.length > 3) {
        errorElement.style.color = 'red';
        setButtonState(1, false)
        return false;
    }

    errorElement.style.color = '';
    setButtonState(1, true)
    return true;
}

window.validateTopics =  validateTopics;

// Get selected topic IDs
function getSelectedTopicIds() {
    return Array.from(document.querySelectorAll('#fs-topics input[type="checkbox"]:checked'))
        .map(checkbox => checkbox.value);
}

// Generate button click handler
async function generate() {
    const button = document.getElementById('generateButton');
    const output = document.getElementById('output');
    const status = document.getElementById('status');

    // Validate form
    if (!validateForm()) {
        return;
    }

    const topicIds = getSelectedTopicIds();
    const formType = document.querySelector('input[name="formType"]:checked')?.value;
    const target = document.querySelector('input[name="target"]:checked')?.value;

    // Clear previous output and move to results step
    output.textContent = '';
    showStep(5);

    try {
        // Build URL with query parameters
        const params = new URLSearchParams();
        params.append('topics', JSON.stringify(topicIds));
        params.append('form_type', formType);
        params.append('target', target);

        button.classList.add('disabled')

        // Add time fillers for the countdown
        const timeFillers = [
            'Rozpoczynam generowanie...',
            'Czytam Miejską Agendę Parkingową...',
            'Czytam Przepisy o Ruchu Drogowym...',
            'Szukam absurdalnych przykładów dla urozmaicenia pisma...',
            'Sprawdzam co w temacie ma do powiedzenia NIK...',
            'Czytam decyzję NSA z 2018 roku...',
            'Patrzę na możliwe rozwiązania...',
            'Sprawdzam statystyki drogowe...',
            'Weryfikuję najczęstsze zgłoszenia...'
        ].sort(() => Math.random() - 0.5);

        let countdown = 40;
        let countdownInterval;

        // Start countdown
        const startCountdown = () => {
            countdownInterval = setInterval(() => {
                if (countdown <= 0) {
                    clearInterval(countdownInterval);
                    return;
                }
                countdown--;
                const filler = timeFillers[Math.floor(timeFillers.length * (countdown / 40))];
                status.textContent = `${filler} (${countdown}s)`;
            }, 1000);
        };

        // Start the countdown
        startCountdown();

        const response = await fetch(`/generator/stream?${params.toString()}`, {
            method: 'GET',
            headers: {
                'Accept': 'text/event-stream'
            }
        });

        // Check if response is OK
        if (!response.ok) {
            const error = await response.text();
            throw new Error(error);
        }

        // Clear the countdown when we start reading
        if (countdownInterval) clearInterval(countdownInterval);

        // Process the stream as text
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();

            if (done) {
                status.textContent = 'Zakończono generowanie';
                break;
            }

            // Decode the chunk
            const chunk = decoder.decode(value, { stream: true });
            buffer += chunk;

            // Process complete events (they end with double newline)
            const events = buffer.split('\n\n');
            buffer = events.pop() || ''; // Keep incomplete event in buffer

            for (const event of events) {
                if (!event.startsWith('data: ')) continue;

                const eventData = event.slice(6).trim();

                // Check for done signal
                if (eventData === '[DONE]') {
                    status.textContent = 'Zakończono generowanie';
                    continue;
                }

                try {
                    const data = JSON.parse(eventData);

                    if (data.error) {
                        throw new Error(data.error);
                    }

                    if (data.content) {
                        output.textContent += data.content;
                        // Auto-scroll to bottom
                        output.scrollTop = output.scrollHeight;
                    }
                } catch (e) {
                    console.error('Error parsing event:', e);
                }
            }
        }

        document.querySelector("section#step-5>h1").innerHTML = 'Wygenerowane pismo';

        // Show and populate email links after successful generation
        populateEmailLinks();

    } catch (error) {
        console.error('Error:', error);
        const parsed = JSON.parse(error.message);
        if (parsed.error) {
            status.textContent = `Błąd: ${parsed.error}`;
        } else {
            status.textContent = `Błąd: ${error.message}`;
        }
    } finally {
        button.classList.remove('disabled')
    }
};

// Function to populate email links with recipient and content
function populateEmailLinks() {
    const selectedTarget = document.querySelector('input[name="target"]:checked');
    const recipient = selectedTarget?.dataset?.recipient;
    let content = document.getElementById('output').textContent;

    if (!recipient || !content) {
        return;
    }

    let subject = 'Pismo w sprawie parkowania';

    // Check if content starts with "Temat: " and extract subject
    if (content.startsWith('Temat: ')) {
        const lines = content.split('\n');
        const firstLine = lines[0];

        // Extract subject from the first line (remove "Temat: " prefix)
        subject = firstLine.substring(7); // "Temat: ".length = 7

        // Remove the first line from content
        content = lines.slice(1).join('\n').trim();
    }

    // Populate mailto link
    const mailtoUrl = `mailto:${recipient}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(content)}`;
    document.getElementById('mailtoButton').href = mailtoUrl;
    document.getElementById('mailtoButton').style.display = 'inline-block';

    // Populate Gmail link
    const gmailUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(recipient)}&su=${encodeURIComponent(subject)}&body=${encodeURIComponent(content)}`;
    document.getElementById('gmailButton').href = gmailUrl;
    document.getElementById('gmailButton').style.display = 'inline-block';
}

