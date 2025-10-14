import { error as errorToast } from "./lib/toast";

let topicsData = [];
let formTypesData = {};
let targetsData = {};
let recipientsData = {};
let currentStep = 1;
let totalSteps = document.querySelectorAll(".generator>section:not(.hidden)").length;
const currentScript = document.currentScript;

const politicalPartyExpanedNames = {
    'PiS': 'Prawo i Sprawiedliwosć',
    'KO': 'Koalicja Obywatelska',
    'Polska2050-TD': 'Polska 2050 - Trzecia Droga',
    'PSL-TD': 'Polskie Stronnictwo Ludowe - Trzecia Droga',
    // these don't need expansion: Lewica, Razem, Konfederacja
};

/**
 * @param {HTMLElement|null} element
 * @pararam {boolean} show
 */
function showHideElement(element, show=true) {
    if (element)
        element.style.setProperty('display', (show ? '': 'none'))
}

/**
 * @param {number} stepNumber
 */
function showStep(stepNumber) {
    document.querySelectorAll('.generator>section').forEach(section => {
        section.classList.remove('active');
    });

    const currentSection = document.getElementById(`step-${stepNumber}`);
    if (currentSection)
        currentSection.classList.add('active')


    const stepNumberSpan = /** @type {HTMLSpanElement} */ (document.querySelector('.generator>header>span'))
    if (stepNumberSpan) {
        stepNumberSpan.textContent = `Krok ${stepNumber} z ${totalSteps - 1}`;
    }

    const header = /** @type {HTMLElement} */ (document.querySelector('.generator>header'))
    const show = ([1, 2, 3, 4].includes(stepNumber))
    showHideElement(header, show)
    showHideElement(stepNumberSpan, show)

    currentStep = stepNumber

    if (currentStep == 4)
        fetchRecipients()
}

function prevStep() {
    if (currentStep > 1)
        showStep(currentStep - 1)
    else
        window.location.href = '/napisz-pismo-do-polityka.html'
}

function nextStep() {
    if (currentStep < totalSteps - 1)
        if (validateCurrentStep())
            showStep(currentStep + 1)
}

function validateCurrentStep() {
    switch (currentStep) {
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
    const formTypeElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="formType"]:checked'));
    const targetElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="target"]:checked'));
    const formType = formTypeElement?.value;
    const target = targetElement?.value;

    if (!formType) {
        setButtonState(2, false, 'Wybierz ton pisma')
        return false;
    }

    if (!target) {
        setButtonState(3, true, 'Wskaż adresata pisma')
        return false;
    }

    return true;
}

function restartWizard() {
    // Reset form
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => /** @type {HTMLInputElement} */(cb).checked = false);
    document.querySelectorAll('input[name="formType"]').forEach(radio => /** @type {HTMLInputElement} */(radio).checked = false);
    document.querySelectorAll('input[name="target"]').forEach(radio => /** @type {HTMLInputElement} */(radio).checked = false);
    const mailtoButton = /** @type {HTMLAnchorElement} */ (document.getElementById('mailtoButton'));
    mailtoButton.href = '#';

    const step5nav = /** @type {HTMLElement} */ (document?.getElementById('step-5__nav'))
    step5nav.style.visibility = 'hidden';

    const outputElement = document.getElementById('output');
    if (outputElement) outputElement.textContent = '';

    const statusElement = document.getElementById('status');
    if (statusElement) statusElement.textContent = 'Gotowy do pisania';

    const stepHeader = document.querySelector("section#step-5>h1");
    if (stepHeader) stepHeader.innerHTML = 'Tworzenie pisma';

    
    showHideElement(document.getElementById('status'))

    const progress = document.getElementById('progress')
    showHideElement(progress)
    progress?.style.setProperty('--progress', '0%');

    // Go back to step 1
    showStep(1);
}

async function fetchRecipients() {
    // show loader
    const loader = document.querySelector(".generator>section#step-4>div.loader");
    loader?.classList.remove('hidden');
    const fieldContainer = document.querySelector(".generator>section#step-4>fieldset");
    document.querySelector('.generator>section#step-4>nav>a')?.classList.add('disabled');
    if (!fieldContainer) return prevStep();
    fieldContainer.innerHTML = '';

    // check the selector type
    const recipient_selector = document.querySelector(".generator>section#step-3>fieldset input:checked")?.dataset?.recipient;

    /* these need to match selectors in inc/data.php */
    if (recipient_selector == "selector:infrastructure_committee_member") {
        const recipientsResponse = await fetch('/generator/suggested_parlamentary');
        recipientsData = await recipientsResponse.json();
        /* waiting for implementation
        } else if (recipient_selector=="selector:parlamentary") {
          const recipientsResponse = await fetch('/generator/parlamentary');
          recipientsData = await recipientsResponse.json(); 
        */
    } else {
        // welp, let's go back
        return prevStep();
    };

    // check if vovoideship_match filter should be shown
    const vovoideshipsValues = [...new Set(Object.values(recipientsData).map(r => r.vovoideship_match))];
    const filterLabel = /** @type {HTMLElement} */ (document.querySelector('.s4_filter'));
    if (filterLabel) {
        showHideElement(filterLabel, vovoideshipsValues.length >= 2)
    }

    // get selected filters
    const selectedFilters = Array.from(document.querySelectorAll('input[name="s4_filter[]"]:checked'))
        .map(checkbox => checkbox.value);

    // function to check if recipient matches selected filters
    const recipientMatchesFilters = (recipient) => {
        if (selectedFilters.length === 0) return true; // no filters selected, show all

        return selectedFilters.every(filter => {
            return recipient[filter] === true;
        });
    };

    // political parties
    const politicalParties = [...new Set(Object.values(recipientsData).map(r => r.party))];

    let rendered = '';

    // render
    for (const party of politicalParties) {
        // get filtered recipients for this party
        const partyRecipients = Object.entries(recipientsData)
            .filter(([name, recipient]) => recipient.party === party && recipientMatchesFilters(recipient));

        // only render party header if there are matching recipients
        if (partyRecipients.length > 0) {
            rendered += `<h4>${politicalPartyExpanedNames[party] || party}</h4>`;
            rendered += partyRecipients.map(([name, recipient]) => `
              <label>
                <input type="radio" name='recipient'
                    value="${name}" onchange="window.checkRecipient()" />
                ${recipient.name}
              </label>
        `).join('');
        }
    };

    fieldContainer.innerHTML = rendered;
    loader?.classList.add('hidden');
}

/** @type {any} */ (window).fetchRecipients = fetchRecipients;

// check recipient and possible next steps
const checkRecipient = () => {
    const selectedRecipient = /** @type {HTMLInputElement} */ (document.querySelector('input[name="recipient"]:checked'));
    const selectedRecipientValue = selectedRecipient?.value;

    const actionButton = /** @type {HTMLButtonElement} */ (document.querySelector('.generator>section#step-4>nav>a'));

    if (!actionButton) return true;

    if (selectedRecipient) {    // we know who is the recipient
        actionButton.innerHTML = 'Dalej';
        actionButton.dataset.action = 'generate';
        actionButton.disabled = false;
        actionButton.classList.remove('disabled');
    } else {
        actionButton.disabled = true;
        actionButton.classList.add('disabled');
    }
    return true;
}

/** @type {any} */ (window).checkRecipient = checkRecipient;

document.addEventListener('DOMContentLoaded', async () => {
    const generatorContainer = document.querySelectorAll('.generator');
    if (generatorContainer.length === 0) return;


    try {
        showStep(1);
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
            const target = /** @type {HTMLElement} */ (e.target);
            if (!target) return;

            if (target.classList.contains('disabled')) {
                return false;
            } else if (target.dataset.action === 'next') {
                nextStep();
            } else if (target.dataset.action === 'prev') {
                prevStep();
            } else if (target.dataset.action === 'restart') {
                restartWizard();
            } else if (target.dataset.action === 'generate') {
                generate();
            }
        });
    } catch (error) {
        console.error('Error initializing data:', error);
        errorToast('Wystąpił błąd podczas ładowania danych. Odśwież stronę i spróbuj ponownie.');
    }
});

// Render topics checkboxes
function renderTopics() {
    const container = document.getElementById('fs-topics');
    if (!container) return;

    container.innerHTML = Object.entries(topicsData).map(([id, topic]) => `
            <label>
              <input type="checkbox" name='topics[]' value="${id}" onchange="window.validateTopics()">
              <div><b>${topic.title}</b>
              <p>${topic.ext}</p>
              </div>
            </label>
        `).join('');
}

// Render form types radio buttons
function renderFormTypes() {
    const container = document.getElementById('fs-types');
    if (!container) return;

    container.innerHTML = Object.entries(formTypesData).map(([id, data], index) => `
            <label>
              <input type="radio" name="formType" value="${id}" onchange="window.renderTargets()">
              ${data || id}
            </label>
        `).join('');
}

const renderTargets = () => {
    const formTypeElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="formType"]:checked'));
    const formType = formTypeElement?.value;
    const container = document.getElementById('fs-targets');
    if (!container) return;

    container.innerHTML = '';

    if (!formType)
        return setButtonState(2, false, 'Wybierz ton pisma');

    setButtonState(2, true, 'Dalej');

    // Filter targets that support the selected form type and have recipient defined
    const availableTargets = Object.entries(targetsData)
        .filter(([_, target]) => target.forms.includes(formType))
        .sort(() => Math.random() - 0.5);

    container.innerHTML = availableTargets.map(([id, target]) => `
            <label>
              <input type="radio" name="target" data-recipient="${target.email || target.form || (target.selector && "selector:" + target.selector)}" onchange="window.checkTarget()" value="${id}">
              ${target.title}
            </label>
        `).join('');

    return true;
}

/** @type {any} */ (window).renderTargets = renderTargets;

// check recipient and possible next steps
const checkTarget = () => {
    const selectedTarget = /** @type {HTMLInputElement} */ (document.querySelector('input[name="target"]:checked'));
    const selectedTargetValue = selectedTarget?.value;
    const selectedTargetRecipient = selectedTarget?.dataset?.recipient;

    const actionButton = /** @type {HTMLButtonElement} */ (document.querySelector('.generator>section#step-3>nav>a'));

    if (!actionButton) return true;

    if (selectedTargetRecipient && selectedTargetRecipient.startsWith("selector:")) { // we need additional step to select the recipient
        document.querySelector(".generator>section#step-4")?.classList.remove("hidden");
        actionButton.innerHTML = 'Dalej';
        actionButton.dataset.action = 'next';
        actionButton.disabled = false;
        actionButton.classList.remove('disabled');
    } else if (selectedTargetRecipient) {    // we know who is the recipient
        document.querySelector(".generator>section#step-4")?.classList.add("hidden");
        actionButton.innerHTML = 'Dalej';
        actionButton.dataset.action = 'generate';
        actionButton.disabled = false;
        actionButton.classList.remove('disabled');
    } else {                          // fallback
        actionButton.disabled = true;
        actionButton.classList.add('disabled');
    }
    totalSteps = document.querySelectorAll(".generator>section:not(.hidden)").length;
    showStep(currentStep); // refresh the step number after manipulating the optional step

    return true;
}

/** @type {any} */ (window).checkTarget = checkTarget;

/**
 * @param {number} step
 * @param {boolean} enable
 * @param {string} [text]
 */
function setButtonState(step, enable, text) {
    const button = /** @type {HTMLElement} */ (document.querySelector(`#step-${step} a.button`));
    if (text && button)
        button.textContent = text
    if (enable)
        return button?.classList.remove('disabled')
    button?.classList.add('disabled')
}

// Validate that 1-3 topics are selected
const validateTopics = () => {
    const selectedTopics = document.querySelectorAll('fieldset#fs-topics input[type="checkbox"]:checked');

    if (selectedTopics.length === 0) {
        setButtonState(1, false, 'Wybierz opcję')
        return false;
    }
    if (selectedTopics.length > 3) {
        setButtonState(1, false, 'Wybierz do 3 opcji')
        return false;
    }

    setButtonState(1, true, 'Dalej')
    return true;
}

/** @type {any} */ (window).validateTopics = validateTopics;

// Get selected topic IDs
function getSelectedTopicIds() {
    return Array.from(document.querySelectorAll('#fs-topics input[type="checkbox"]:checked'))
        .map(checkbox => /** @type {HTMLInputElement} */(checkbox).value);
}

// Generate button click handler
async function generate() {
    const button = /** @type {HTMLButtonElement} */ (document.getElementById('generateButton'));
    const output = /** @type {HTMLElement} */ (document.getElementById('output'));
    const status = /** @type {HTMLElement} */ (document.getElementById('status'));

    // Validate form
    if (!validateForm() || !output) {
        return;
    }

    const topicIds = getSelectedTopicIds();
    const formTypeElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="formType"]:checked'));
    const targetElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="target"]:checked'));
    const recipientElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="recipient"]:checked'));
    const progressBar = /** @type {HTMLInputElement} */ document.getElementById('progress');
    const formType = formTypeElement?.value;
    const target = targetElement?.value;
    const recipient = (targetElement?.dataset?.recipient?.startsWith('selector:') && recipientElement?.value);

    // Clear previous output and move to results step
    // start with formal salutation
    if (recipient && recipientsData[recipient]) {
        output.textContent = recipientsData[recipient].formal + "\n\n";
    } else if (target && targetsData[target]) {
        output.textContent = targetsData[target].formal + "\n\n";
    } else {
        // should not happen
        output.textContent = "";
    }
    showStep(5);

    try {
        // Build URL with query parameters
        const params = new URLSearchParams();
        params.append('topics', JSON.stringify(topicIds));
        if (formType) params.append('form_type', formType);
        if (target) params.append('target', target);
        if (recipient) params.append('recipient', recipient);

        if (button) button.classList.add('disabled');

        // Add time fillers for the countdown
        const timeFillers = [
            'Analizuję doświadczenia użytkowników Uprzejmie Donoszę...',
            'Czytam decyzję NSA z 2018 roku...',
            'Czytam Miejską Agendę Parkingową...',
            'Czytam Przepisy o Ruchu Drogowym...',
            'Czytam wyroki sądów...',
            'Oglądam filmy Konfitury...',
            'Patrzę na możliwe rozwiązania...',
            'Sprawdzam co w temacie ma do powiedzenia NIK...',
            'Sprawdzam statystyki wypadków drogowych...',
            'Szukam absurdalnych przykładów dla urozmaicenia pisma...',
            'Weryfikuję najczęstsze zgłoszenia...'
        ].sort(() => Math.random() - 0.5);

        let counter = 0;
        const waitingTime = 40;
        let countdownInterval;

        // Start countdown
        const startCountdown = () => {
            countdownInterval = setInterval(() => {
                if (counter >= waitingTime) {
                    clearInterval(countdownInterval);
                    return;
                }
                counter++;
                const filler = timeFillers[Math.floor(timeFillers.length * (counter / (waitingTime + 1)))];
                if (status) status.textContent = `${filler}`;


                progressBar?.style.setProperty('--progress', Math.round(100 * counter / waitingTime) + '%');
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
        if (!response.body) throw new Error('No response body');
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();

            if (done) {
                if (status) status.textContent = 'Gotowe do wysłania';
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
                    if (status) status.textContent = 'Gotowe do wysłania';
                    continue;
                }

                try {
                    const data = JSON.parse(eventData);

                    if (data.error) {
                        throw new Error(data.error);
                    }

                    if (data.content && output) {
                        output.textContent += data.content;
                        output.scrollTo({
                            top: output.scrollHeight,
                            behavior: 'smooth'
                        });
                    }
                } catch (e) {
                    errorToast('Error parsing event: ' + e);
                }
            }
        }

        const stepHeader = /** @type {HTMLElement} */ (document.querySelector("section#step-5>h1"));
        if (stepHeader) stepHeader.innerHTML = 'Twoje pismo do ' + targetsData[target].title;
        showHideElement(status, false)
        showHideElement(progressBar, false)

        populateDeliveryLinks();

    } catch (error) {
        console.error(error)

        try {
            const parsed = JSON.parse(error.message);
            if (parsed.error && status) {
                errorToast(`Błąd: ${parsed.error}`)
            } else if (status) {
                errorToast(`Błąd: ${error.message}`)
            }
        } catch {
            if (status) errorToast(`Błąd: ${error.message}`)
        }
    } finally {
        if (button) button.classList.remove('disabled');
    }
};

function populateDeliveryLinks() {
    const mailtoButton = /** @type {HTMLAnchorElement} */ (document?.getElementById('mailtoButton'))
    const gmailtoButton = /** @type {HTMLAnchorElement} */ (document?.getElementById('gmailtoButton'))
    const formButton = /** @type {HTMLAnchorElement} */ (document?.getElementById('openFormButton'))
    if (!mailtoButton || !gmailtoButton)
        return errorToast('Missing mailtoButton or gmailtoButton')

    const targetElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="target"]:checked'));
    const recipientElement = /** @type {HTMLInputElement} */ (document.querySelector('input[name="recipient"]:checked'));
    const target = targetElement?.value;
    const recipient = (targetElement?.dataset?.recipient?.startsWith('selector:') && recipientElement?.value);

    const output = /** @type {HTMLDivElement} */ document?.getElementById('output')
    const outputShadow = /** @type {HTMLDivElement} */ document?.getElementById('output-shadow')

    if (!target)
        return errorToast('Missing target')

    let recipient_action
    if (recipient && recipientsData[recipient]?.email)
        recipient_action = recipientsData[recipient].email;
    else if (targetElement?.dataset?.recipient)
        recipient_action = targetElement.dataset.recipient;

    if (!recipient_action)
        return errorToast('Missing target')

    let content = output?.textContent;
    if (outputShadow)
        outputShadow.textContent = content || ''
    if (!content)
        return errorToast('Missing content')

    const searchForSubject = /Temat: (.*)/.exec(content)
    const subject = searchForSubject?.pop() || 'Pismo w sprawie przepisów związanych z parkowaniem'

    const emailDelivery = recipient_action.search('@') > 0
    showHideElement(formButton, !emailDelivery)
    showHideElement(mailtoButton, emailDelivery)
    showHideElement(gmailtoButton, emailDelivery)

    if (emailDelivery) {
        if (outputShadow) {
            content = content.replace(/\n?\s*\n?^Temat:.*$/m, '')
            outputShadow.textContent =
                "Wyślij wiadomość na adres: " + recipient_action + "\n" +
                "Temat: " + subject + "\n\n" + content
        }
        const loggedInToGmail = currentScript?.getAttribute("data-user-isgmail") == '1'
        if (loggedInToGmail) {
            gmailtoButton.classList.add('cta')
        } else {
            mailtoButton.classList.add('cta')
        }
        mailtoButton.href = getEmailUrl(recipient_action, subject, content)
        gmailtoButton.href = getGmailUrl(recipient_action, subject, content)
    } else {
        formButton.href = recipient_action
    }

    const step5nav = /** @type {HTMLElement} */ (document?.getElementById('step-5__nav'))
    step5nav.style.visibility = 'visible';
}

function getGmailUrl(recipient, subject, content) {
    const bcc = "miejska@agendaparkingowa.pl";
    return `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(
        recipient
    )}&bcc=${encodeURIComponent(bcc)}&su=${encodeURIComponent(
        subject
    )}&body=${encodeURIComponent(content)}`
}

function getEmailUrl(recipient, subject, content) {
    const bcc = "miejska@agendaparkingowa.pl";
    return `mailto:${encodeURIComponent(
        recipient
    )}?bcc=${encodeURIComponent(bcc)}&subject=${encodeURIComponent(
        subject
    )}&body=${encodeURIComponent(content)}`
}

