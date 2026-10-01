import * as React from 'react'
import * as ReactDOM from 'react-dom'
import { assertPanelReactMajor } from './reactMajor'

/*
 * The first module of the test runtime: before anything else loads, a test
 * run on another React major than the panel's fails, naming the fix.
 * `__MARTIS_REACT_VERSION__` is the package's own React, injected by
 * vite.testing.config.ts.
 */
assertPanelReactMajor(__MARTIS_REACT_VERSION__, { react: React.version, reactDom: ReactDOM.version })

export {}
