// A DOM small enough for the cart module and big enough to run it.
//
// The cart module paints the page, listens for clicks and schedules
// timers, so a runtime test needs a page to paint on. This file is that
// page: the smallest DOM that answers the calls resources/js/cart.js
// actually makes. It is not a browser — it implements exactly the surface
// the cart touches (elements, attributes, datasets, class lists, a
// bubbling click, a toast host) and nothing else.
//
// The rules of the house apply here too: no new dependency. Node's own
// runtime is the only thing this needs.

const toDatasetKey = (name) =>
    name.replace(/^data-/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());

class FakeClassList {
    constructor(element) {
        this.element = element;
    }

    get names() {
        return new Set(String(this.element.className || '').split(/\s+/).filter(Boolean));
    }

    set names(set) {
        this.element.className = Array.from(set).join(' ');
    }

    add(...names) {
        const set = this.names;

        names.forEach((name) => set.add(name));
        this.names = set;
    }

    remove(...names) {
        const set = this.names;

        names.forEach((name) => set.delete(name));
        this.names = set;
    }

    contains(name) {
        return this.names.has(name);
    }

    toggle(name, force) {
        const set = this.names;
        const wanted = force === undefined ? !set.has(name) : Boolean(force);

        if (wanted) {
            set.add(name);
        } else {
            set.delete(name);
        }

        this.names = set;

        return wanted;
    }
}

class FakeText {
    constructor(text) {
        this.nodeType = 3;
        this.textContent = String(text);
        this.parentNode = null;
    }
}

class FakeElement {
    constructor(tagName) {
        this.tagName = String(tagName).toUpperCase();
        this.childNodes = [];
        this.parentNode = null;
        this.dataset = {};
        this.attributes = {};
        this.className = '';
        this.hidden = false;
        this.text = '';
        this.listeners = {};
        this.style = { setProperty() {} };
        this.classList = new FakeClassList(this);
    }

    get textContent() {
        if (this.childNodes.length === 0) return this.text;

        return this.childNodes.map((node) => node.textContent ?? '').join('');
    }

    set textContent(value) {
        this.text = String(value);
        this.childNodes = [];
    }

    /**
     * The markup an element carries.
     *
     * Some of the admin pages build their rows as a string and assign it
     * to innerHTML. There is no HTML parser in here — the markup is kept
     * as it was written, and the text of it is kept alongside so a test
     * can read what a rendered row says.
     */
    get innerHTML() {
        return this._innerHTML ?? '';
    }

    set innerHTML(value) {
        this._innerHTML = String(value);
        this.childNodes = [];
        this.text = this._innerHTML.replace(/<[^>]*>/g, '');
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);

        if (name.startsWith('data-')) {
            this.dataset[toDatasetKey(name)] = String(value);
        }
    }

    getAttribute(name) {
        if (name.startsWith('data-')) {
            const key = toDatasetKey(name);

            return key in this.dataset ? this.dataset[key] : null;
        }

        return name in this.attributes ? this.attributes[name] : null;
    }

    hasAttribute(name) {
        return this.getAttribute(name) !== null;
    }

    removeAttribute(name) {
        delete this.attributes[name];

        if (name.startsWith('data-')) {
            delete this.dataset[toDatasetKey(name)];
        }
    }

    appendChild(child) {
        child.parentNode = this;
        this.childNodes.push(child);

        return child;
    }

    replaceChildren(...nodes) {
        this.childNodes = [];

        nodes.forEach((node) => this.appendChild(node));
    }

    append(...nodes) {
        nodes.forEach((node) => this.appendChild(node));
    }

    remove() {
        if (!this.parentNode) return;

        this.parentNode.childNodes = this.parentNode.childNodes.filter((node) => node !== this);
        this.parentNode = null;
    }

    descendants() {
        const out = [];

        for (const child of this.childNodes) {
            if (child instanceof FakeElement) {
                out.push(child, ...child.descendants());
            }
        }

        return out;
    }

    matches(selector) {
        return String(selector)
            .split(',')
            .some((part) => matchesSimple(this, part.trim()));
    }

    querySelector(selector) {
        return this.descendants().find((node) => node.matches(selector)) ?? null;
    }

    querySelectorAll(selector) {
        return this.descendants().filter((node) => node.matches(selector));
    }

    closest(selector) {
        let node = this;

        while (node) {
            if (node instanceof FakeElement && node.matches(selector)) return node;

            node = node.parentNode;
        }

        return null;
    }

    addEventListener(type, handler) {
        this.listeners[type] = this.listeners[type] || [];
        this.listeners[type].push(handler);
    }

    dispatchEvent(type, event) {
        (this.listeners[type] || []).forEach((handler) => handler(event));
    }

    /**
     * Focus is a no-op here — there is no focus to move to — but the
     * scripts call it, and a missing method would stop them dead.
     */
    focus() {}

    blur() {}
}

function matchesSimple(element, selector) {
    if (!selector) return false;

    let rest = selector;

    const tag = rest.match(/^[a-zA-Z][\w-]*/);
    if (tag) {
        if (element.tagName !== tag[0].toUpperCase()) return false;

        rest = rest.slice(tag[0].length);
    }

    // An id, `#name`. Without this an id selector would match every
    // element on the page, and a script that writes to "the element
    // with that id" would write to the wrong one.
    const ids = rest.match(/#[A-Za-z_][\w-]*/g) || [];
    for (const raw of ids) {
        if (element.getAttribute('id') !== raw.slice(1)) return false;
    }

    rest = rest.replace(/#[A-Za-z_][\w-]*/g, '');

    const classes = rest.match(/\.[\w-]+/g) || [];
    for (const className of classes) {
        if (!element.classList.contains(className.slice(1))) return false;
    }

    rest = rest.replace(/\.[\w-]+/g, '');

    const attributes = rest.match(/\[[^\]]*\]/g) || [];
    for (const raw of attributes) {
        const inner = raw.slice(1, -1);
        const equals = inner.indexOf('=');

        if (equals === -1) {
            if (!element.hasAttribute(inner)) return false;

            continue;
        }

        const name = inner.slice(0, equals).trim();
        const value = inner.slice(equals + 1).trim().replace(/^["']|["']$/g, '');

        if (String(element.getAttribute(name) ?? '') !== value) return false;
    }

    return true;
}

/**
 * Build the browser globals the cart module expects.
 *
 * The page itself is built by the caller — it decides which hooks the
 * page carries. This only provides the plumbing.
 */
export function createBrowser() {
    const root = new FakeElement('html');
    const documentListeners = {};
    const windowListeners = {};

    const document = {
        readyState: 'complete',
        documentElement: root,
        body: root,
        createElement: (tag) => new FakeElement(tag),
        createTextNode: (text) => new FakeText(text),
        querySelector: (selector) => root.querySelector(selector),
        querySelectorAll: (selector) => root.querySelectorAll(selector),
        getElementById: (id) => root.descendants().find((node) => node.getAttribute('id') === id) ?? null,
        addEventListener: (type, handler) => {
            documentListeners[type] = documentListeners[type] || [];
            documentListeners[type].push(handler);
        },
        dispatchEvent: (type, event) => {
            (documentListeners[type] || []).forEach((handler) => handler(event));
        },
    };

    const window = {
        CSS: { escape: (value) => String(value) },
        location: { assign() {} },
        addEventListener: (type, handler) => {
            windowListeners[type] = windowListeners[type] || [];
            windowListeners[type].push(handler);
        },
        dispatchEvent: (type, event) => {
            (windowListeners[type] || []).forEach((handler) => handler(event));
        },
    };

    const store = new Map();

    const localStorage = {
        getItem: (key) => (store.has(key) ? store.get(key) : null),
        setItem: (key, value) => {
            store.set(key, String(value));
        },
        removeItem: (key) => {
            store.delete(key);
        },
        clear: () => store.clear(),
    };

    return { document, window, localStorage, root, FakeElement };
}

/**
 * Publish the browser globals on globalThis so the cart module — which
 * reads `document`, `window` and `localStorage` as globals — finds them.
 */
export function installGlobals(browser, fetchImpl) {
    globalThis.document = browser.document;
    globalThis.window = browser.window;
    globalThis.localStorage = browser.localStorage;
    globalThis.fetch = fetchImpl;
    globalThis.requestAnimationFrame = (callback) => setTimeout(callback, 0);
}

/**
 * Fire a click on an element and let it bubble the way a real one does.
 */
export function click(element) {
    const event = {
        target: element,
        currentTarget: element,
        preventDefault() {},
        stopPropagation() {},
    };

    let node = element;

    while (node) {
        if (node instanceof FakeElement) {
            // The handler belongs to this node, so this node is the
            // one the event is currently on.
            event.currentTarget = node;
            (node.listeners.click || []).forEach((handler) => handler(event));
        }

        node = node.parentNode;
    }
}
