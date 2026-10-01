/** The options of {@link martisExtensionTestConfig}. */
export interface MartisExtensionTestConfigOptions {
  /** The app's root, the current directory by default. */
  root?: string
  /** The Martis package below the root, `vendor/martis/martis` by default. */
  packageDir?: string
}

/** The Vitest config fragment of the Martis test kit, for `mergeConfig()`. */
export function martisExtensionTestConfig(options?: MartisExtensionTestConfigOptions): {
  resolve: {
    preserveSymlinks: boolean
    alias: Array<{ find: string | RegExp; replacement: string }>
  }
  test: {
    environment: 'jsdom'
    setupFiles: string[]
  }
}
