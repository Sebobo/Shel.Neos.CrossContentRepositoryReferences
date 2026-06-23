/* eslint-disable @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-function-type, @typescript-eslint/no-empty-object-type, @typescript-eslint/no-unused-vars */
declare module '*.module.css';
declare module '*.module.scss';

// ---------------------------
// Types from the Neos UI core
// ---------------------------
type I18nRegistry = {
    translate: (
        id?: string,
        fallback?: string,
        params?: Record<string, unknown> | string[],
        packageKey?: string,
        sourceName?: string,
    ) => string;
};

// ---------------------------
// External modules provided at runtime by the Neos UI host.
// They are aliased to vendor shims via the extensibility map at build time
// (see esbuild.js), so we only need loose ambient declarations here for
// type-checking.
// ---------------------------
declare module 'react' {
    const React: any;
    export default React;
    export function useContext<T>(context: any): T;
    export function useState<S>(initial: S | (() => S)): [S, (value: S | ((prev: S) => S)) => void];
    export function useState<S = undefined>(): [S | undefined, (value: S | ((prev: S | undefined) => S)) => void];
    export function useEffect(effect: () => void | (() => void), deps?: unknown[]): void;
    export function useCallback<T extends Function>(cb: T, deps: unknown[]): T;
    export function useMemo<T>(factory: () => T, deps: unknown[]): T;
    export function useRef<T>(initial: T): { current: T };
    export const createContext: any;
    export type FC<P = {}> = (props: P) => any;
    export type Context<T> = any;
}

declare module 'react-redux' {
    export const useSelector: any;
    export const connect: any;
}

declare module '@neos-project/react-ui-components' {
    export const SelectBox: any;
    export const MultiSelectBox: any;

    export const SelectBox_Option_MultiLineWithThumbnail: any;
}

declare module '@neos-project/neos-ui-redux-store' {
    export const selectors: any;
}

declare module '@neos-project/neos-ui-i18n' {
    export function translate(
        address: string,
        fallback?: string | [string, string],
        params?: any,
        quantity?: number,
    ): string;
}

declare module '@neos-project/neos-ui-decorators' {
    import type { Context } from 'react';
    export const NeosContext: Context<any>;
    export const neos: any;
}

declare module '@neos-project/neos-ui-extensibility' {
    const manifest: any;
    export default manifest;
}

declare module '@neos-project/neos-ui-registry' {
    export class SynchronousRegistry<T = any> {
        constructor(description?: string);
        set(key: string, value: T): void;
        get(key: string): T;
    }
}
