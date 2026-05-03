import { describe, it, expect, beforeEach, afterEach } from "vitest"
import { render, screen } from "@testing-library/react"
import { MartisLoader, DefaultMartisLoader } from "@/components/Loader"
import { componentRegistry } from "@/lib/componentRegistry"
import { useResourceLoaderConfig } from "@/contexts/LoaderConfigContext"

describe("MartisLoader wrapper — registry override", () => {
  beforeEach(() => {
    componentRegistry.clear()
  })

  afterEach(() => {
    componentRegistry.clear()
  })

  it("renders the default loader when no override is registered", () => {
    render(<MartisLoader loading message="loading default" />)
    expect(screen.getByText("loading default")).toBeDefined()
  })

  it("renders the registered override component when one is registered", () => {
    function CustomLoader() {
      return <div data-testid="custom-loader">custom</div>
    }
    componentRegistry.register("loader", CustomLoader)

    render(<MartisLoader loading />)
    expect(screen.getByTestId("custom-loader")).toBeDefined()
  })

  it("forwards configOverride to the underlying component", () => {
    render(
      <MartisLoader
        loading
        configOverride={{ message: "Per-call override message" }}
      />,
    )
    expect(screen.getByText("Per-call override message")).toBeDefined()
  })
})

describe("useResourceLoaderConfig", () => {
  it("pushes an override visible to the wrapper while the page is mounted", () => {
    function PageWithOverride() {
      useResourceLoaderConfig({ message: "Resource-pushed message" })
      return <MartisLoader loading />
    }

    render(<PageWithOverride />)
    expect(screen.getByText("Resource-pushed message")).toBeDefined()
  })

  it("falls back to the global loader config when override is empty", () => {
    function PageWithEmptyOverride() {
      useResourceLoaderConfig({})
      return <MartisLoader loading message="explicit fallback" />
    }

    render(<PageWithEmptyOverride />)
    expect(screen.getByText("explicit fallback")).toBeDefined()
  })

  it("respects context disableOn=true coming from the resource override", () => {
    function PageDisablingDetail() {
      useResourceLoaderConfig({ disableOn: { detail: true } })
      // `context="detail"` would normally render; the resource opts out.
      return (
        <div>
          <MartisLoader loading message="should not appear" context="detail" />
          <span>page chrome</span>
        </div>
      )
    }

    render(<PageDisablingDetail />)
    expect(screen.queryByText("should not appear")).toBeNull()
    expect(screen.getByText("page chrome")).toBeDefined()
  })
})

describe("DefaultMartisLoader — context disableOn matrix", () => {
  it("returns null when context matches disableOn override", () => {
    const { container } = render(
      <DefaultMartisLoader
        loading
        context="search"
        configOverride={{ disableOn: { search: true } }}
        message="hidden"
      />,
    )
    expect(container.textContent).toBe("")
  })

  it("renders when context is not in disableOn", () => {
    render(
      <DefaultMartisLoader
        loading
        context="components"
        configOverride={{ disableOn: { search: true } }}
        message="visible"
      />,
    )
    expect(screen.getByText("visible")).toBeDefined()
  })
})
