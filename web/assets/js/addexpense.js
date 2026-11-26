/**
 * Add-expense form behaviour:
 *  - defaults the date to today and blocks future dates
 *  - live "remaining budget" preview with a non-blocking over-budget warning
 *  - toggles the free-text "Other" category / payment inputs
 *  - asks the ML categorizer for a category suggestion as the description is typed
 */
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("expenseForm");
    if (!form) {
        return;
    }

    const today = localDateString(new Date());
    const dateInput = document.getElementById("date");
    dateInput.value = dateInput.value || today;
    dateInput.setAttribute("max", today);

    const budgetValue = document.getElementById("monthly_budget").value;
    const monthlyBudget = budgetValue === "" ? null : parseFloat(budgetValue);
    const totalExpenses = parseFloat(document.getElementById("total_expenses").value) || 0;
    const remainingField = document.getElementById("remaining_budget");
    const warning = document.getElementById("budget-warning");
    const costInput = document.getElementById("cost");

    costInput.addEventListener("input", function () {
        if (monthlyBudget === null) {
            return;
        }
        const entered = parseFloat(this.value) || 0;
        const remaining = monthlyBudget - totalExpenses - entered;
        remainingField.value = remaining.toFixed(2);
        warning.style.display = remaining < 0 ? "block" : "none";
    });

    document.getElementById("category").addEventListener("change", toggleOtherCategory);
    document.getElementById("payment_method").addEventListener("change", toggleOtherPayment);
    toggleOtherCategory();
    toggleOtherPayment();

    form.addEventListener("submit", function (event) {
        if (dateInput.value > today) {
            alert("You cannot add an expense for a future date!");
            event.preventDefault();
        }
    });

    setupCategorySuggestion();
});

function localDateString(date) {
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${date.getFullYear()}-${month}-${day}`;
}

function toggleOtherCategory() {
    const select = document.getElementById("category");
    document.getElementById("otherCategoryDiv").style.display = select.value === "Other" ? "block" : "none";
}

function toggleOtherPayment() {
    const select = document.getElementById("payment_method");
    document.getElementById("otherPaymentDiv").style.display = select.value === "Other" ? "block" : "none";
}

/** Debounced call to api/ml_categorize.php; shows clickable top-3 category suggestions. */
function setupCategorySuggestion() {
    const description = document.getElementById("item");
    const box = document.getElementById("category-suggestion");
    const categorySelect = document.getElementById("category");
    if (!description || !box) {
        return;
    }
    const csrf = form_csrf_token();
    let timer = null;
    let lastQuery = "";

    const request = function () {
        const text = description.value.trim();
        if (text.length < 3 || text === lastQuery) {
            return;
        }
        lastQuery = text;
        fetch(box.dataset.endpoint, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({
                description: text,
                amount: parseFloat(document.getElementById("cost").value) || null,
                payment_method: document.getElementById("payment_method").value
            })
        })
            .then(response => response.ok ? response.json() : null)
            .then(data => render(data))
            .catch(() => { box.style.display = "none"; });
    };

    const render = function (data) {
        box.textContent = "";
        if (!data || !Array.isArray(data.suggestions) || data.suggestions.length === 0) {
            box.style.display = "none";
            return;
        }
        const label = document.createElement("span");
        label.textContent = "Suggested category: ";
        box.appendChild(label);
        data.suggestions.forEach(function (suggestion) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "suggestion-chip";
            button.textContent = `${suggestion.category} (${Math.round(suggestion.probability * 100)}%)`;
            button.addEventListener("click", function () {
                const exists = Array.from(categorySelect.options).some(o => o.value === suggestion.category);
                categorySelect.value = exists ? suggestion.category : "Other";
                if (!exists) {
                    document.getElementById("other_category").value = suggestion.category;
                }
                toggleOtherCategory();
            });
            box.appendChild(button);
        });
        box.style.display = "block";
    };

    description.addEventListener("input", function () {
        clearTimeout(timer);
        timer = setTimeout(request, 400);
    });
}

function form_csrf_token() {
    const field = document.querySelector('#expenseForm input[name="csrf_token"]');
    return field ? field.value : "";
}
