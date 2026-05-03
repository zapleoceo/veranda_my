(() => {
  const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches

  const dom = {
    root: document.documentElement,
    details: document.querySelector('.lang-menu'),
    panelLinks: Array.from(document.querySelectorAll('.lang-panel a')),
    get content() { return document.getElementById('menuContent') },
  }

  if (!dom.panelLinks.length || !dom.content) return

  const cookieSet = (name, value) => {
    const maxAge = 31536000
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; Path=/; Max-Age=${maxAge}; SameSite=Lax`
  }

  const langFromHref = (href) => {
    const m = String(href || '').match(/[?&]lang=([a-zA-Z-]+)/)
    return m ? m[1].toLowerCase() : ''
  }

  const view = {
    setActive(lang) {
      for (const a of dom.panelLinks) {
        const code = langFromHref(a.getAttribute('href') || '')
        a.classList.toggle('active', code === lang)
      }
    },
    swap(oldNode, newNode) {
      if (reduced) { oldNode.replaceWith(newNode); return }
      const parent = oldNode.parentElement
      if (!parent) { oldNode.replaceWith(newNode); return }

      const wrap = document.createElement('div')
      wrap.className = 'i18n-xfade'
      wrap.style.display = 'grid'

      const oldLayer = document.createElement('div')
      oldLayer.className = 'i18n-layer i18n-layer--old'
      oldLayer.appendChild(oldNode)

      const newLayer = document.createElement('div')
      newLayer.className = 'i18n-layer i18n-layer--new'
      newLayer.appendChild(newNode)

      parent.replaceChild(wrap, oldNode)
      wrap.append(oldLayer, newLayer)
      wrap.getBoundingClientRect()

      requestAnimationFrame(() => {
        if (!document.body.contains(wrap)) return
        wrap.classList.add('is-animating')
      })

      window.setTimeout(() => { wrap.replaceWith(newNode) }, 280)
    },
  }

  const meta = {
    copyFromDoc(fromDoc) {
      const map = [
        ['title', 'title'],
        ['meta[name="description"]', 'meta[name="description"]'],
        ['link[rel="canonical"]', 'link[rel="canonical"]'],
        ['meta[property="og:title"]', 'meta[property="og:title"]'],
        ['meta[property="og:description"]', 'meta[property="og:description"]'],
        ['meta[property="og:url"]', 'meta[property="og:url"]'],
        ['meta[property="og:image"]', 'meta[property="og:image"]'],
        ['meta[name="twitter:title"]', 'meta[name="twitter:title"]'],
        ['meta[name="twitter:description"]', 'meta[name="twitter:description"]'],
        ['meta[name="twitter:image"]', 'meta[name="twitter:image"]'],
      ]

      for (const [selFrom, selTo] of map) {
        if (selTo === 'title') { document.title = fromDoc.title || document.title; continue }
        const src = fromDoc.querySelector(selFrom)
        const dst = document.querySelector(selTo)
        if (!src || !dst) continue
        if (dst.tagName === 'LINK') {
          const href = src.getAttribute('href')
          if (href) dst.setAttribute('href', href)
        } else {
          const content = src.getAttribute('content')
          if (content != null) dst.setAttribute('content', content)
        }
      }

      const jsonLd = fromDoc.querySelector('script[type="application/ld+json"]')
      const curJsonLd = document.querySelector('script[type="application/ld+json"]')
      if (jsonLd && curJsonLd) curJsonLd.textContent = jsonLd.textContent || ''
    }
  }

  const state = {
    capture() {
      const cur = dom.content
      const openKeys = cur ? Array.from(cur.querySelectorAll('details[data-key][open]')).map((d) => String(d.getAttribute('data-key') || '')).filter(Boolean) : []
      return { openKeys, scrollY: window.scrollY }
    },
    apply(node, s) {
      if (!node || !s) return
      const openSet = new Set(Array.isArray(s.openKeys) ? s.openKeys : [])
      node.querySelectorAll('details[data-key]').forEach((d) => {
        const k = String(d.getAttribute('data-key') || '')
        if (openSet.has(k)) d.open = true
      })
    }
  }

  const controller = {
    async loadLang(href) {
      const s = state.capture()
      const url = new URL(href, window.location.href)
      const lang = (url.searchParams.get('lang') || '').toLowerCase()
      if (!lang) return

      try {
        const r = await fetch(url.toString(), { headers: { 'Accept': 'text/html' } })
        if (!r.ok) throw new Error('bad')
        const html = await r.text()
        const doc = new DOMParser().parseFromString(html, 'text/html')
        const nextContent = doc.getElementById('menuContent')
        if (!nextContent) throw new Error('no')

        nextContent.id = 'menuContent'
        state.apply(nextContent, s)
        dom.root.setAttribute('lang', doc.documentElement.getAttribute('lang') || lang)
        meta.copyFromDoc(doc)
        view.setActive(lang)
        cookieSet('links_lang', lang)
        window.history.replaceState({}, '', url.toString())

        const current = dom.content
        if (!current) { window.location.href = url.toString(); return }
        view.swap(current, nextContent)

        window.setTimeout(() => {
          const y = Number(s && s.scrollY != null ? s.scrollY : 0) || 0
          window.scrollTo(0, y)
        }, 320)
      } catch (_) {
        window.location.href = url.toString()
      }
    },
    bind() {
      for (const a of dom.panelLinks) {
        a.addEventListener('click', (e) => {
          if (a.classList.contains('active')) return
          e.preventDefault()
          if (dom.details) dom.details.open = false
          controller.loadLang(a.getAttribute('href') || '')
        })
      }
    }
  }

  controller.bind()
})()
