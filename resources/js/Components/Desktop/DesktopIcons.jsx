import { Box, Text, Title } from '@mantine/core';
import { useWindow } from './WindowContext';
import { useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { DragDropContext, Droppable, Draggable } from '@hello-pangea/dnd';
import {
    IconFolder,
    IconSettings,
    IconTerminal2,
    IconBrandDocker,
    IconActivity,
    IconDisc,
    IconShield,
} from '@tabler/icons-react';

// Map icon name strings to Tabler React components
const ICON_MAP = {
    IconFolder,
    IconSettings,
    IconTerminal2,
    IconBrandDocker,
    IconActivity,
    IconDisc,
    IconShield,
};

export function DesktopIcons({ apps = [], onIconPositionChange }) {
    const { windows, openWindow, focusWindow } = useWindow();
    const [isDragging, setIsDragging] = useState(false);
    const containerRef = useRef(null);

    // Local state to track order - initialized from apps prop
    const [iconOrder, setIconOrder] = useState([]);

    // Initialize order from apps only once when apps first load
    useEffect(() => {
        if (apps.length > 0 && iconOrder.length === 0) {
            // Sort by order if available
            const sortedApps = [...apps].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
            const initialOrder = sortedApps.map(app => app.desktopAppId || app.id);
            setIconOrder(initialOrder);
        }
    }, [apps]); // Only run when apps changes, not when iconOrder changes

    // Handle double click to open window
    const handleDoubleClick = useCallback((app) => {
        const existingWindow = windows.find(w => w.appId === app.id && !w.minimized);
        if (existingWindow) {
            focusWindow(existingWindow.id);
        } else {
            const IconComponent = ICON_MAP[app.iconName] || IconFolder;
            openWindow(app.id, app.name, IconComponent);
        }
    }, [windows, openWindow, focusWindow]);

    // Handle single click to open window
    const handleClick = useCallback((app) => {
        const IconComponent = ICON_MAP[app.iconName] || IconFolder;
        openWindow(app.id, app.name, IconComponent);
    }, [openWindow]);

    // Handle drag start
    const handleDragStart = useCallback(() => {
        setIsDragging(true);
    }, []);

    // Handle drag end - save new order to backend
    const handleDragEnd = useCallback((result) => {
        setIsDragging(false);

        const { destination, source, draggableId } = result;

        // Dropped outside the list
        if (!destination) {
            return;
        }

        // No change in position
        if (destination.index === source.index) {
            return;
        }

        // Reorder locally first for instant feedback
        const newOrder = Array.from(iconOrder);
        const [removed] = newOrder.splice(source.index, 1);
        newOrder.splice(destination.index, 0, removed);

        setIconOrder(newOrder);

        // Prepare order data for backend
        const orders = newOrder.map((appId, index) => ({
            desktop_app_id: parseInt(appId),
            order: index,
        }));

        // Save to backend
        if (onIconPositionChange) {
            onIconPositionChange(orders);
        }
    }, [iconOrder, onIconPositionChange]);

    // Get apps in current order - only recalculate when iconOrder actually changes
    const displayApps = useMemo(() => {
        if (iconOrder.length === 0 && apps.length > 0) {
            // Initialize order from apps on first render
            const sortedApps = [...apps].sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
            return sortedApps;
        }

        // Map order to apps
        return iconOrder
            .map(id => apps.find(app => (app.desktopAppId || app.id) === id))
            .filter(Boolean);
    }, [iconOrder, apps]);

    return (
        <Box
            ref={containerRef}
            style={{
                position: 'absolute',
                top: 0,
                left: 0,
                right: 0,
                bottom: 0,
                padding: '20px',
                display: 'flex',
                flexDirection: 'column',
                alignContent: 'flex-start',
                gap: '16px',
                overflow: 'auto',
            }}
        >
            <Box style={{ padding: '0 4px 16px 4px' }}>
                <Text size="lg" fw={600} c="white" style={{ textShadow: '0 1px 2px rgba(0, 0, 0, 0.5)' }}>
                    Applications
                </Text>
                <Text size="xs" c="dimmed">
                    Drag and drop to reorganize
                </Text>
            </Box>
            <DragDropContext onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
                <Droppable droppableId="desktop-icons" direction="horizontal">
                    {(provided) => (
                        <Box
                            ref={provided.innerRef}
                            {...provided.droppableProps}
                            style={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                alignContent: 'flex-start',
                                gap: '16px',
                                width: '100%',
                                height: '100%',
                            }}
                        >
                            {displayApps.map((app, index) => {
                                return (
                                    <Draggable
                                        key={app.id}
                                        draggableId={String(app.desktopAppId || app.id)}
                                        index={index}
                                    >
                                        {(provided, snapshot) => (
                                            <Box
                                                ref={provided.innerRef}
                                                {...provided.draggableProps}
                                                {...provided.dragHandleProps}
                                                style={{
                                                    ...provided.draggableProps.style,
                                                    display: 'flex',
                                                    flexDirection: 'column',
                                                    alignItems: 'center',
                                                    gap: '8px',
                                                    padding: '8px',
                                                    borderRadius: '8px',
                                                    cursor: snapshot.isDragging ? 'grabbing' : 'pointer',
                                                    opacity: snapshot.isDragging ? 0.8 : 1,
                                                    zIndex: snapshot.isDragging ? 1000 : 1,
                                                }}
                                                onDoubleClick={() => handleDoubleClick(app)}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleClick(app);
                                                }}
                                                className="desktop-icon"
                                            >
                                                <Box
                                                    style={{
                                                        width: '64px',
                                                        height: '64px',
                                                        borderRadius: '16px',
                                                        backgroundColor: app.color,
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                        boxShadow: snapshot.isDragging
                                                            ? '0 8px 24px rgba(0, 0, 0, 0.4)'
                                                            : '0 4px 12px rgba(0, 0, 0, 0.3)',
                                                    }}
                                                >
                                                {(() => {
                                                    const IconComponent = ICON_MAP[app.iconName] || IconFolder;
                                                    return <IconComponent size={32} color="white" />;
                                                })()}
                                                </Box>
                                                <Text
                                                    size="sm"
                                                    c="white"
                                                    style={{
                                                        textAlign: 'center',
                                                        textShadow: '0 1px 2px rgba(0, 0, 0, 0.5)',
                                                        fontWeight: 500,
                                                        lineHeight: 1.2,
                                                        maxWidth: '90px',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                    }}
                                                >
                                                    {app.name}
                                                </Text>
                                            </Box>
                                        )}
                                    </Draggable>
                                );
                            })}
                            {provided.placeholder}
                        </Box>
                    )}
                </Droppable>
            </DragDropContext>
        </Box>
    );
}
