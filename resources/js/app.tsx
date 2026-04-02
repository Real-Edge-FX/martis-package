import { createRoot } from 'react-dom/client'

function MartisApp() {
    return (
        <div style={{ padding: '2rem', fontFamily: 'system-ui, sans-serif' }}>
            <h1>Martis Admin</h1>
            <p>Laravel Admin Engine — em desenvolvimento.</p>
        </div>
    )
}

const container = document.getElementById('martis-root')
if (container) {
    const root = createRoot(container)
    root.render(<MartisApp />)
}
