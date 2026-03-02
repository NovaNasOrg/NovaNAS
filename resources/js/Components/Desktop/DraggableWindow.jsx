import { useState, useRef, useEffect } from 'react';
import { Box, Text, Group, ActionIcon, Tooltip, useMantineTheme } from '@mantine/core';
import { IconX, IconMaximize, IconCopy } from '@tabler/icons-react';
import { useWindow } from './WindowContext';

const SNAP_THRESHOLD = 20;

export function DraggableWindow({ windowState, children }) {
    const {
        closeWindow,
        maximizeWindow,
        focusWindow,
        moveWindow,
        resizeWindow,
    } = useWindow();

    const [isDragging, setIsDragging] = useState(false);
    const [isResizing, setIsResizing] = useState(false);
    const [resizeDirection, setResizeDirection] = useState(null);
    const dragStartPos = useRef({ x: 0, y: 0 });
    const windowPos = useRef({ x: windowState.x, y: windowState.y });
    const windowSize = useRef({ width: windowState.width, height: windowState.height });
    const theme = useMantineTheme();

    useEffect(() => {
        if (windowState.maximized) {
            windowPos.current = { x: 0, y: 0 };
            windowSize.current = { width: windowState.width, height: windowState.height };
        }
    }, [windowState.maximized, windowState.width, windowState.height]);

    const handleMouseDown = (e) => {
        if (windowState.maximized) return;
        focusWindow(windowState.id);
        setIsDragging(true);
        dragStartPos.current = { x: e.clientX, y: e.clientY };
        windowPos.current = { x: windowState.x, y: windowState.y };
    };

    const handleResizeStart = (direction, e) => {
        if (windowState.maximized) return;
        e.stopPropagation();
        focusWindow(windowState.id);
        setIsResizing(true);
        setResizeDirection(direction);
        dragStartPos.current = { x: e.clientX, y: e.clientY };
        windowPos.current = { x: windowState.x, y: windowState.y };
        windowSize.current = { width: windowState.width, height: windowState.height };
    };

    useEffect(() => {
        if (!isDragging && !isResizing) return;

        const handleMouseMove = (e) => {
            if (isDragging) {
                const deltaX = e.clientX - dragStartPos.current.x;
                const deltaY = e.clientY - dragStartPos.current.y;
                let newX = windowPos.current.x + deltaX;
                let newY = windowPos.current.y + deltaY;

                const screenWidth = globalThis.window.innerWidth;
                const screenHeight = globalThis.window.innerHeight;

                const snapLeft = newX <= SNAP_THRESHOLD;
                const snapRight = newX >= screenWidth - windowState.width - SNAP_THRESHOLD;
                const snapTop = newY <= SNAP_THRESHOLD;

                if (snapLeft) newX = 0;
                if (snapRight) newX = screenWidth - windowState.width;
                if (snapTop) newY = 0;

                moveWindow(windowState.id, newX, newY);
            }

            if (isResizing) {
                const deltaX = e.clientX - dragStartPos.current.x;
                const deltaY = e.clientY - dragStartPos.current.y;
                let newX = windowPos.current.x;
                let newY = windowPos.current.y;
                let newWidth = windowSize.current.width;
                let newHeight = windowSize.current.height;

                if (resizeDirection.includes('e')) {
                    newWidth = Math.max(300, windowSize.current.width + deltaX);
                }
                if (resizeDirection.includes('w')) {
                    newWidth = Math.max(300, windowSize.current.width - deltaX);
                    if (newWidth > 300) {
                        newX = windowPos.current.x + deltaX;
                    }
                }
                if (resizeDirection.includes('s')) {
                    newHeight = Math.max(200, windowSize.current.height + deltaY);
                }
                if (resizeDirection.includes('n')) {
                    newHeight = Math.max(200, windowSize.current.height - deltaY);
                    if (newHeight > 200) {
                        newY = windowPos.current.y + deltaY;
                    }
                }

                moveWindow(windowState.id, newX, newY);
                resizeWindow(windowState.id, newWidth, newHeight);
            }
        };

        const handleMouseUp = () => {
            setIsDragging(false);
            setIsResizing(false);
            setResizeDirection(null);
        };

        globalThis.window.addEventListener('mousemove', handleMouseMove);
        globalThis.window.addEventListener('mouseup', handleMouseUp);

        return () => {
            globalThis.window.removeEventListener('mousemove', handleMouseMove);
            globalThis.window.removeEventListener('mouseup', handleMouseUp);
        };
    }, [isDragging, isResizing, resizeDirection, windowState.id, moveWindow, resizeWindow, windowState.width]);

    if (windowState.minimized) {
        return null;
    }

    return (
        <Box
            style={{
                position: 'absolute',
                left: windowState.maximized ? 0 : windowState.x,
                top: windowState.maximized ? 0 : windowState.y,
                width: windowState.maximized ? '100%' : windowState.width,
                height: windowState.maximized ? '100%' : windowState.height,
                zIndex: windowState.zIndex,
                display: 'flex',
                flexDirection: 'column',
                backgroundColor: theme.colors.dark[8],
                borderRadius: windowState.maximized ? 0 : '8px',
                boxShadow: windowState.maximized
                    ? 'none'
                    : '0 4px 20px rgba(0, 0, 0, 0.5)',
                overflow: 'hidden',
            }}
            onMouseDown={() => focusWindow(windowState.id)}
        >
            {/* Window Title Bar */}
            <Box
                onMouseDown={handleMouseDown}
                style={{
                    height: '36px',
                    backgroundColor: theme.colors.dark[7],
                    borderBottom: `1px solid ${theme.colors.dark[5]}`,
                    cursor: windowState.maximized ? 'default' : 'move',
                    flexShrink: 0,
                    display: 'flex',
                    alignItems: 'center',
                    padding: '0 8px',
                    userSelect: 'none',
                }}
            >
                <Group justify="space-between" style={{ width: '100%' }}>
                    <Group gap="xs">
                        <Text size="sm" c="white" fw={500}>
                            {windowState.title}
                        </Text>
                    </Group>
                    <Group gap={4}>
                        <Tooltip label={windowState.maximized ? 'Restore' : 'Maximize'}>
                            <ActionIcon
                                variant="subtle"
                                color="gray"
                                size="sm"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    maximizeWindow(windowState.id);
                                }}
                            >
                                {windowState.maximized ? (
                                    <IconCopy size={14} />
                                ) : (
                                    <IconMaximize size={14} />
                                )}
                            </ActionIcon>
                        </Tooltip>
                        <Tooltip label="Close">
                            <ActionIcon
                                variant="subtle"
                                color="red"
                                size="sm"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    closeWindow(windowState.id);
                                }}
                            >
                                <IconX size={14} />
                            </ActionIcon>
                        </Tooltip>
                    </Group>
                </Group>
            </Box>

            {/* Window Content */}
            <Box
                style={{
                    flex: 1,
                    overflow: 'auto',
                    backgroundColor: theme.colors.dark[8],
                }}
            >
                {children}
            </Box>

            {/* Resize Handles */}
            {!windowState.maximized && (
                <>
                    <Box
                        style={{
                            position: 'absolute',
                            top: 0,
                            left: 0,
                            width: '8px',
                            height: '100%',
                            cursor: 'ew-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('w', e)}
                    />
                    <Box
                        style={{
                            position: 'absolute',
                            top: 0,
                            right: 0,
                            width: '8px',
                            height: '100%',
                            cursor: 'ew-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('e', e)}
                    />
                    <Box
                        style={{
                            position: 'absolute',
                            top: 0,
                            left: 0,
                            width: '100%',
                            height: '8px',
                            cursor: 'ns-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('n', e)}
                    />
                    <Box
                        style={{
                            position: 'absolute',
                            bottom: 0,
                            left: 0,
                            width: '100%',
                            height: '8px',
                            cursor: 'ns-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('s', e)}
                    />
                    <Box
                        style={{
                            position: 'absolute',
                            top: 0,
                            left: 0,
                            width: '12px',
                            height: '12px',
                            cursor: 'nwse-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('nw', e)}
                    />
                    <Box
                        style={{
                            position: 'absolute',
                            top: 0,
                            right: 0,
                            width: '12px',
                            height: '12px',
                            cursor: 'nesw-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('ne', e)}
                    />
                    <Box
                        style={{
                            position: 'absolute',
                            bottom: 0,
                            left: 0,
                            width: '12px',
                            height: '12px',
                            cursor: 'nesw-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('sw', e)}
                    />
                    <Box
                        style={{
                            position: 'absolute',
                            bottom: 0,
                            right: 0,
                            width: '12px',
                            height: '12px',
                            cursor: 'nwse-resize',
                        }}
                        onMouseDown={(e) => handleResizeStart('se', e)}
                    />
                </>
            )}
        </Box>
    );
}
