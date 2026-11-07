(function() {
    window.OCA = window.OCA || {};
    window.OCA.__APP_NAMESPACE__ = window.OCA.__APP_NAMESPACE__ || {};
    window.OCA.__APP_NAMESPACE__.Shared = window.OCA.__APP_NAMESPACE__.Shared || {};
    const shared = window.OCA.__APP_NAMESPACE__.Shared;

    class Model {
        static get(data) {
            if (data == null) {
                return null;
            }
            return data instanceof this ? data : new this(data || {});
        }

        static get_all(items = []) {
            if (!Array.isArray(items)) {
                return [];
            }
            return items.map(item => this.get(item)).filter(Boolean);
        }

        toArray() {
            throw new Error(`${this.constructor.name} muss toArray implementieren.`);
        }

        to_array() {
            return this.toArray();
        }

        save() {
            throw new Error(`${this.constructor.name} kann nicht direkt gespeichert werden.`);
        }
    }

    shared.models = shared.models || {};
    shared.models.Model = Model;
})();
