// Kayıt açma formu (resources/views/cleanings/create.blade.php). Form JS olmadan da çalışır;
// bu modül yalnızca kolaylık katar:
// - seçilen makinenin prosedür özetini ve başlamamış kayıt uyarısını (K-05) gösterir,
// - malzeme zorunluysa ipucunu gösterir (K-12),
// - iş emirlerini seçilen makineye göre süzer (K-19; asıl kontrol sunucuda),
// - malzeme satırı ekler ve çıkarır.

const INDEX_PLACEHOLDER = /__INDEX__/g;

export default function cleaningForm(form) {
    const machineSelect = form.querySelector('[data-machine-select]');
    const summaries = form.querySelector('[data-machine-summaries]');
    const materialHint = form.querySelector('[data-material-required-hint]');
    const workOrderSelect = form.querySelector('[data-work-order-select]');
    const workOrderEmpty = form.querySelector('[data-work-order-empty]');

    if (machineSelect) {
        if (summaries) {
            summaries.hidden = false;
        }

        const update = () => {
            const machine = selectedMachine(machineSelect);

            showSummary(summaries, machine);

            if (materialHint) {
                materialHint.hidden = machine?.dataset.materialRequired !== '1';
            }

            filterWorkOrders(workOrderSelect, workOrderEmpty, machine);
        };

        machineSelect.addEventListener('change', update);
        update();
    }

    setUpMaterialRows(form);
}

function selectedMachine(select) {
    const option = select.selectedOptions[0];

    return option && option.value !== '' ? option : null;
}

function showSummary(container, machine) {
    if (!container) {
        return;
    }

    const id = machine?.value ?? '';

    container.querySelectorAll('[data-machine-summary]').forEach((summary) => {
        summary.hidden = summary.dataset.machineSummary !== id;
    });
}

/**
 * WorkOrder::isUsableFor ile aynı koşul: iş emri bir makineye ya da hatta bağlıysa yalnızca o
 * makinede kullanılabilir. Gizlenen seçenek ayrıca devre dışı bırakılır (bazı mobil tarayıcılar
 * gizli seçeneği yine de listeler). Seçili iş emri artık uygun değilse seçim kaldırılır.
 */
function filterWorkOrders(select, emptyHint, machine) {
    if (!select) {
        return;
    }

    let usableCount = 0;

    Array.from(select.options).forEach((option) => {
        if (option.value === '') {
            return;
        }

        const usable = machine === null || isUsableFor(option, machine);

        option.hidden = !usable;
        option.disabled = !usable;
        usableCount += usable ? 1 : 0;
    });

    if (select.selectedOptions[0]?.disabled) {
        select.value = '';
    }

    if (emptyHint) {
        emptyHint.hidden = machine === null || usableCount > 0;
    }
}

function isUsableFor(workOrder, machine) {
    const { machineId, lineId } = workOrder.dataset;

    return (!machineId || machineId === machine.value)
        && (!lineId || lineId === machine.dataset.lineId);
}

function setUpMaterialRows(form) {
    const rows = form.querySelector('[data-material-rows]');
    const template = form.querySelector('[data-material-template]');
    const addButton = form.querySelector('[data-material-add]');

    if (!rows || !template || !addButton) {
        return;
    }

    let nextIndex = Number.parseInt(rows.dataset.nextIndex, 10) || rows.children.length;

    rows.querySelectorAll('[data-material-remove]').forEach((button) => {
        button.hidden = false;
    });
    addButton.hidden = false;

    addButton.addEventListener('click', () => {
        const row = createRow(template, nextIndex);
        nextIndex += 1;

        rows.append(row);
        row.querySelector('select, input')?.focus();
    });

    rows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-material-remove]');

        if (!button) {
            return;
        }

        button.closest('[data-material-row]')?.remove();
        addButton.focus();
    });
}

function createRow(template, index) {
    const fragment = template.content.cloneNode(true);
    const row = fragment.querySelector('[data-material-row]');

    row.querySelectorAll('[id], [for], [name], [aria-describedby]').forEach((element) => {
        ['id', 'for', 'name', 'aria-describedby'].forEach((attribute) => {
            const value = element.getAttribute(attribute);

            if (value !== null) {
                element.setAttribute(attribute, value.replace(INDEX_PLACEHOLDER, String(index)));
            }
        });
    });

    row.querySelectorAll('[data-material-remove]').forEach((button) => {
        button.hidden = false;
    });

    return row;
}
