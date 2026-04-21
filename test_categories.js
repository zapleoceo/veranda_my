const PAYDAY_CONFIG = {
    localSettings: {
        allowed_categories: [4],
        custom_category_names: {4: "Test custom name"}
    }
};
const ls = PAYDAY_CONFIG.localSettings;
const allowed = ls.allowed_categories || [];
console.log(allowed.includes(4)); // True
console.log(allowed.includes("4")); // False
