---
title:	"Adding Covariance and Contravariance to Simple Injector"
date:	2011-10-01
author: Steven van Deursen
tags:   [.NET General, C#, Dependency Injection, Simple Injector]
draft:	false
---

### A few weeks back I read [this question](http://stackoverflow.com/questions/7010236/customizing-autofacs-component-resolution-issue-with-generic-co-contravarianc) on Stack Overflow. The question was about applying [covariance / contravariance](http://msdn.microsoft.com/en-us/library/dd799517.aspx) (or variance for short) to the Autofac dependency injection container. The question triggered me to think about variance support in Simple Injector. I was wondering whether special changes were needed to the core framework to allow this. However, it didn't take me long to realize that enabling variance is actually pretty easy for anyone using the Simple Injector. The prerequisites are a proper application design and .NET 4.0.

#### **[UPDATE [2015-01]:** Simple Injector 2.7 and up now will resolve all variant elements of a *collection* by default, which means that most of the advice given in this article is outdated. Please read the Simple Injector [documentation on variance support](https://simpleinjector.org/advanced#covariance-contravariance) for the latest information.

What's nice about Simple Injector, IMO, is that although it has a small API, it has a few very cleverly chosen extensibility points, which make it possible to add almost every imaginable advanced scenario. The examples in [the advanced scenarios section](https://simpleinjector.org/advanced) in the documentation prove this point.

Although the extensibility points of Simple Injector are fine, adding out of the box support for variance still isn't possible. It is not a limitation of Simple Injection however; it is the possible variety of application designs that makes it impossible to come with a single solution that works for everybody.

For instance, what should the container do when an unregistered (variant) service type is requested? It should probably go look for a registration that is assignable from that requested type. But what if there are multiple compatible registrations? Should the container throw an exception? Should the container use the registration that is closest in the inheritance hierarchy to the requested type? And what to do when there are multiple types that are just as close? Or should the container return a list of all compatible registrations (possibly wrapped in a composite). And should this behavior hold for all types in the container, should it just be enabled for a set or types, or should the behavior differ per registration?

In the end, it is the application designer / developer that must decide what the correct behavior is, and because of this, it is impossible for a library to pick the correct behavior. Because of this, I'm not adding such feature to Simple Injector. But as I said, it is pretty easy to do it yourself, and I will describe how to in the rest of this article.

If you are still reading this, I assume you are one of the few who needs variance support -or- you are (like me) a geek who likes to think about this sort of stuff, just because it is fun :-). Below I'll describe three scenarios that I think are the most common ones to appear when you need to apply variance. Below I'll define an `IEventHandler<TEvent>` interface with supporting classes that I'll be using as example for the rest of this article.

Event handlers are a good example to show case variance support, because they are likely part of the application design that would benifit from it. Event handlers are used more and more in common architecture. CQRS is good example of an architecture that uses them extensively. When a single event is raised (by a command for instance), it seems reasonable to set up the application in such way that multiple event handlers would trigger that event. But to go a step further, imagine the definition of a `CustomerMovedAbroadEvent` that inherits from a `CustomerMovedEvent`. An event handler that handles a `CustomerMovedEvent` can technically also handle a `CustomerMovedAbroadEvent` (if it doesn't work, we would be breaking the Liskov substitution principle anyway). Not only would we want to have multiple event handlers to handle a single `CustomerMovedEvent` event, we also want those same handlers to handle a `CustomerMovedAbroadEvent` event. For this scenario we need to add contravariance support (or atleast, contravariance will make it much easier to implement this).

The next code snippet shows a `CustomerMovedEvent` class with two derived classes: `CustomerMovedAbroadEvent` and `SpecialCustomerMovedEvent`. For simplicity I removed all properties. These event classes typically contain just data and no behavior (separating data and behavior is an important concept when it comes to applying dependency injection). Behavior is defined in event handlers, all implementing the generic `IEventHandler<in TEvent>` interface. Note the C# 4.0 in keyword in the `IEventHandler<in TEvent>`. This keyword allows variance (contravariance in this case) support.

{{< highlight csharp >}}
// Events
public class CustomerMovedEvent { }
public class CustomerMovedAbroadEvent : CustomerMovedEvent { }
public class SpecialCustomerMovedEvent : CustomerMovedEvent { }

// Generic handler interface
public interface IEventHandler<in TEvent>
{
    void Handle(TEvent e);
}

// Handler implementations
public class CustomerMovedEventHandler : IEventHandler<CustomerMovedEvent>
{
    public void Handle(CustomerMovedEvent e) { ... }
}

public class NotifyStaffWhenCustomerMovedEventHandler
	: IEventHandler<CustomerMovedEvent>
{
    public void Handle(CustomerMovedEvent e) { ... }
}

public class CustomerMovedAbroadEventHandler
	: IEventHandler<CustomerMovedAbroadEvent>
{
    public void Handle(CustomerMovedAbroadEvent e) { ... }
} 
{{< / highlight >}}

The use of the `in` keyword allows us to write the following (compiling) code:

{{< highlight csharp >}}
IEventHandler<CustomerMovedEvent> handler =  new CustomerMovedEventHandler();

// This next line works because of the 'in' keyword
IEventHandler<CustomerMovedAbroadEvent> handler2 = handler;

handler2.Handle(new CustomerMovedAbroadEvent());
{{< / highlight >}}

As I explained, event handlers are a good example to show case variance support, because they are a likely part of the application design to need variance support. When raising a single `CustomerMovedAbroadEvent` event, it seems reasonably to set up the application in such way that all three previously defined event handlers will handle that event.

Letting multiple event handlers handle a single event is perhaps the most likely and also the nicest scenario to implement. So let's start with this one:

## Scenario 1

### Register multiple implementations of the same service type, and resolving multiple assignable services, wrapped in a composite.

In this scenario we would like to configure the container in such way that when we request a single event handler for an event, it would return us a composite handler that would forward the event to all assignable event handlers, with the possibility of having multiple handlers that share the exact same service type. In other words, with the previously defined event handlers, the following code should lead to the execution of all three event handlers:

{{< highlight csharp >}}
var handler = container.GetInstance<IEventHandler<CustomerMovedAbroadEvent>>();

handler.Handle(new CustomerMovedAbroadEvent());
{{< / highlight >}}

Note that the `CustomerMovedEventHandler` and the `NotifyStaffWhenCustomerMovedEventHandler` both handle the `CustomerMovedEvent` event and they are both assignable from `IEventHandler<CustomerMovedAbroadEvent>`. In other words, because `CustomerMovedAbroadEvent` inherits from `CustomerMovedEvent`, both handlers are able to handle a `CustomerMovedAbroadEvent`. And of course the container's configuration should enable this.

By defining a composite event handler that forwards the event to the wrapped handlers, we will be able to hide this design from the application. Consumers will not have to depend on `IEnumerable<IEventHandler<TEvent>>`, but can simply depend on `IEventHandler<TEvent>`, which is of course much more convenient.

**TIP:** *As a general advice, you should prevent injecting lists of services into consumers if you can, by wrapping that list in a composite, and inject that composite into consumers. Not wrapping the list in a composite would clutter the application with extra foreach loops. While this doesn't seem bad, the consumers shouldn't care, but worse, when we want to change the way the list of services is handled, we will have to go through the complete application, which is a violation of the DRY principle.*

For this to work we need to define the composite. Without the need for any (contra)variance, the simplest way to define such composite, would be something like this:

{{< highlight csharp >}}
public sealed class MultipleDispatchEventHandler<TEvent>
	: IEventHandler<TEvent>
{
    private IEnumerable<IEventHandler<TEvent>> handlers;

    public MultipleDispatchEventHandler(
		IEnumerable<IEventHandler<TEvent>> handlers)
    {
        this.handlers = handlers;
    }

    void IEventHandler<TEvent>.Handle(TEvent e)
    {
        foreach (var handler in this.handlers)
        {
            handler.Handle(e);
        }
    }
}
{{< / highlight >}}

This class takes a dependency on `IEnumerable<IEventHandler<TEvent>>` and all it does is iterating that collection and calling `Handle(e)` on each handler in the collection. Note that although it takes a dependency on `IEnumerable<IEventHandler<TEvent>>`, the rest of the application will be oblivious of the `IEnumerable` and it can simply take a dependency on `IEventHandler<TEvent>`. Contravariance will make this class a bit more complex though, but let's get back on that later. Let's first focus on the registration of the event handlers.

The `CustomerMovedEventHandler` and `NotifyStaffWhenCustomerMovedEventHandler` share the same interface: `IEventHandler<CustomerMovedEvent>`. Unlike other IoC containers, Simple Injector does not allow implicit registration of multiple implementations of the same type. For instance, the following code snippet will fail at runtime:

{{< highlight csharp >}}
container.Register<IEventHandler<CustomerMovedEvent>,
	CustomerMovedEventHandler>();
container.Register<IEventHandler<CustomerMovedEvent>,
	NotifyStaffWhenCustomerMovedEventHandler>();
{{< / highlight >}}

When running this code, it will fail on the second line, because at that point `IEventHandler<CustomerMovedEvent>` has already been registered. The actual way to do this with Simple Injector is by using one of the `RegisterAll` methods, for instance:

{{< highlight csharp >}}
container.RegisterAll<IEventHandler<CustomerMovedEvent>>(
    typeof(CustomerMovedEventHandler),
    typeof(NotifyStaffWhenCustomerMovedEventHandler));

container.RegisterAll<IEventHandler<CustomerMovedAbroadEvent>>(
    typeof(CustomerMovedAbroadEventHandler));
{{< / highlight >}}

These `RegisterAll` methods actually register a single enumerable that calls back into the container on iteration. The actual types won't get registered themselves, but since they are concrete types, the container will be able to create them anyway. For educational purposes, the previous example is roughly translated by Simple Injector to the following:

These `RegisterAll` methods actually register a single enumerable that calls back into the container on iteration. The actual types won't get registered themselves, but since they are concrete types, the container will be able to create them anyway. For educational purposes, the previous example is roughly translated by Simple Injector to the following:

{{< highlight csharp >}}
var types = new[]
{
    typeof(CustomerMovedEventHandler), 
    typeof(NotifyStaffWhenCustomerMovedEventHandler)
};

container.RegisterSingle<IEnumerable<IEventHandler<CustomerMovedEvent>>>(
    from type in types
    select (IEventHandler<CustomerMovedEvent>)container.GetInstance(type)
);
{{< / highlight >}}

Although the `RegisterAll` overloads are pretty easy to grasp, it gets cumbersome when the application has dozens of event handlers. The same can therefore be achieved in a more automated way by doing batch registration:

{{< highlight csharp >}}
container.RegisterManyForOpenGeneric(typeof(IEventHandler<>),
    (service, impls) => container.RegisterAll(service, impls),
    AppDomain.CurrentDomain.GetAssemblies());
{{< / highlight >}}

This registration tells the container to go look for all concrete implementations of the `IEventHandler<TEvent>` interface in all (already loaded) assemblies of the current App Domain. The other `RegisterManyForOpenGeneric` overloads that don't take a delegate, directly register the found implementations in the container. Those overloads are however not able to handle service types with multiple registrations (as is the case with the `IEventHandler<CustomerMovedEvent>`). By supplying a delegate, we inform the container that it should not do the registration itself, but rather delegate that back to the supplied registration callback. For each found service type (in our case `IEventHandler<CustomerMovedEvent>` and `IEventHandler<CustomerMovedAbroadEvent>`) the delegate will be called once, and the supplied delegate will call the `RegisterAll` method to register a collection of the given service type. In the delegate we call the same `RegisterAll` overload as was done in the first example.

With this configuration, these registrations can be resolved by calling `container.GetInstance<IEnumerable<T>>()` or by calling `container.GetAllInstances<T>()` (which is a short cut to `GetInstance<IEnumerable<T>>()`). A registered collection could also be injected in a constructor that takes an `IEnumerable<T>`, as we've seen with the `MultipleDispatchEventHandler<TEvent>`. Constructor injection always has the preference over calling the container directly.

With this configuration in place we can now focus again on the `MultipleDispatchEventHandler<TEvent>`. Although the earlier implementation of the `MultipleDispatchEventHandler<TEvent>` works effectively as a composite, it doesn't handle the contravariance that we need for these event handlers. Because it is not feasible to configure the container in such way that assignable implementations are injected too, we can best solve this inside the `MultipleDispatchEventHandler`'s constructor. Here's how to do it:

{{< highlight csharp >}}
public sealed class MultipleDispatchEventHandler<TEvent>
	: IEventHandler<TEvent>
{
    private IEnumerable<IEventHandler<TEvent>> handlers;

    public MultipleDispatchEventHandler(Container container)
    {
        var handlersType = typeof(IEnumerable<IEventHandler<TEvent>>);

        var handlersCollection = (
            from r in container.GetCurrentRegistrations()
            where handlersType.IsAssignableFrom(r.ServiceType)
            select r.GetInstance())
            .Cast<IEnumerable<IEventHandler<TEvent>>>()
            .ToArray();

        this.handlers =
            from handlers in handlersCollection
            from handler in handlers
            select handler;
    }

    void IEventHandler<TEvent>.Handle(TEvent e)
    {
        foreach (var handler in this.handlers)
        {
            handler.Handle(e);
        }
    }
}
{{< / highlight >}}

The code in the constructor isn't that hard to grasp, but it probably still needs some explanation. Instead of injecting an `IEnumerable<IEventHandler<TEvent>>` we now inject the container itself. The `MultipleDispatchEventHandler` constructor iterates over all registrations in the container and gets all registrations where `IEnumerable<IEventHandler<TEvent>>` is assignable from the registration's service type. Remember that we registered the handlers using `IEnumerable<T>`. Although the application contains three event handlers, they are contained in two `IEnumerable<IEventHandler<TEvent>>` registrations.

The query results in a collection of event handler collections. Calling ToArray() on the query triggers the immediate execution of that query, which prevents the list of registrations to be evaluated every time that collection is iterated (which will maximize performance). Leaving the elements of the array enumerables (instead of arrays) however, allows the event handlers to be lazily requested, which preserves their lifestyle (which is transient in the current configuration). This allows us to register the `MultipleDispatchEventHandler` as singleton. This will again maximize performance.

Note that because the `MultipleDispatchEventHandler` contains registration logic, it is clearly part of the Composition Root; the startup path of the application. Do not place this class inside the application, because this will force the application to have a dependency on the IoC container, which is bad practice.

The only thing missing now is the registration of the `MultipleDispatchEventHandler` itself, which is a simple one-liner:

{{< highlight csharp >}}
container.RegisterSingleOpenGeneric(typeof(IEventHandler<>),
    typeof(MultipleDispatchEventHandler<>));
{{< / highlight >}}

With this in place we now have completed our first scenario.

## Scenario 2

### Register a single implementation of a service type, and resolve a single instance.

In this scenario we want to configure the container in such way that when we request a single event handler for a particular event, it would return the single registered instance for that event, or is case it doesn't exist, return a compatible registered event handler.

**Note**: *Although this scenario is perhaps a bit less likely for the example using event handlers, but it is still a realistic one, and Autofac partially supports this scenario out of the box.*

Because there is a one-to-one mapping between the registered service type and the implementation, we can use the 'normal' way of registering event handlers:

{{< highlight csharp >}}
container.Register<IEventHandler<CustomerMovedEvent>, CustomerMovedEventHandler>();
container.Register<IEventHandler<CustomerMovedAbroadEvent>,
    CustomerMovedAbroadEventHandler>();
{{< / highlight >}}

And of course we can achieve the same using batch registration:

{{< highlight csharp >}}
container.RegisterManyForOpenGeneric(typeof(IEventHandler<>),
    typeof(IEventHandler<>).Assembly);
{{< / highlight >}}

Note that the `NotifyStaffWhenCustomerMovedEventHandler` is not included, since this scenario implies a single implementation per handler type, but that handler shares the same interface with the `CustomerMovedEventHandler`.

With this configuration we can of course resolve an `IEventHandler<CustomerMovedEvent>` without trouble, but resolving an `IEventHandler<SpecialCustomerMovedEvent>` will fail with an `ActivationException`. What's missing is the resolution of unresolved types. We can do this by hooking onto the `ResolveUnregisteredType` event of the `Container` class, just as the `RegisterOpenGeneric` and `RegisterSingleOpenGeneric` extension methods of the SimpleInjector.Extensions.dll do internally.

We can for instance define a single extension method that allows mapping a missing type to an assignable type:
{{< highlight csharp >}}
public static void AllowToResolveVariantTypes(this Container container)
{
    container.ResolveUnregisteredType += (sender, e) =>
    {
        Type serviceType = e.UnregisteredServiceType;

        if (!serviceType.IsGenericType)
        {
            return;
        }

        Type def = serviceType.GetGenericTypeDefinition();

        var registrations = (
            from r in container.GetCurrentRegistrations()
            where r.ServiceType.IsGenericType
            where r.ServiceType.GetGenericTypeDefinition() == def
            where serviceType.IsAssignableFrom(r.ServiceType)
            select r)
            .ToArray();            
            
        if (!registrations.Any())
        {
            // No registration found. We're done.
        }
        else if (registrations.Length == 1)
        {
            var registration = registrations[0];
            e.Register(registration.BuildExpression());
        }
        else
        {
            var names = string.Join(", ", registrations
                .Select(r => string.Format("{0}", r.ServiceType)));

            throw new ActivationException(string.Format(
                "It is impossible to resolve type {0}, because there are {1} " +
                "registrations that are applicable. Ambiguous registrations: {2}.",
                serviceType, registrations.Length, names));
        }
    };
}
{{< / highlight >}}

This extension method registers a delegate to the container's ResolveUnregisteredType event. Every time an unregistered type is requested from the container, the container will first call the ResolveUnregisteredType to get a resolution for that type, before it will try to create that type itself. The delegate is supplied with an UnregisteredTypeEventArgs that contains a Register(Expression) method, which allows to register type. The delegate doesn't have to call the Register method, and that is what happens in this extension method; If the UnregisteredServiceType is not generic, the delegate returns immediately, which allows other registered delegates (if any) to respond by registering a Expression for that service type.

The registered delegate will query the container for assignable registrations, much like we've seen in the first scenario. When no assignable service type is found in the container, the delegate returns. Otherwise it maps the found registration to the unregistered service type (making that unregistered type effectively registered). In case there are multiple assignable registrations, an exception is thrown. In that case there is obviously some ambiguity in the registration. Of course we could try to be smarter than this, and try to resolve this ambiguity. Throwing however is the simplest thing to do :-).

What's nice about this, is that it works for both covariant (out) and contravariant (`in`) types (both for generic interfaces and generic delegates) and even for types with a mixture of `in` and `out` arguments (such as `Func<in T, out TResult>`). The reason this works is because we make use of the .NET 4.0 Type.IsAssignableFrom method, which has built-in support for variance. Compare that to Autofac for instance where support is limited to interfaces that just contain a single in type argument (out is not supported). Not to pick on Autofac btw. It is the only container that has out-of-the-box support for variance.

Please note that although there is an obvious performance hit when an unregistered type is resolved this way, the penalty is one-time. The registration is cached, and although the container does not guarantee the delegate to be called just once, in normal cases it will be called just a single time. (however, you will have to make sure the delegate hooked to the ResolveUnregisteredType event is thread-safe, but in most cases this is a no-brainer).

With this extension method in place, we can add variance support to the container like this:

{{< highlight csharp >}}
container.AllowToResolveVariantTypes();
{{< / highlight >}}

With the previous registration we can now resolve the following type, even though it wasn't registered explicitly:

{{< highlight csharp >}}
var handler = container.GetInstance<IEventHandler<SpecialCustomerMovedEvent>>();

Assert.IsInstanceOfType(handler, typeof(CustomerMovedEventHandler));
{{< / highlight >}}

## Scenario 3
### Register a single implementation for some service type, and resolve multiple assignable services.

This scenario is a mix between scenario 1 and scenario 2. As with the second scenario we have just a single implementation for each service type, but want to resolve all compatible handlers for a given event, just as with scenario 1.

We can for instance register the two event handlers the same way as we did in the previous scenario:

{{< highlight csharp >}}
container.Register<IEventHandler<CustomerMovedEvent>, CustomerMovedEventHandler>();
container.Register<IEventHandler<CustomerMovedAbroadEvent>,
    CustomerMovedAbroadEventHandler>();
{{< / highlight >}}

When we wrap this in a composite, we get a solution that looks very much like the first scenario. The MultipleDispatchEventHandler would look as follows:

{{< highlight csharp >}}
public sealed class MultipleDispatchEventHandler<TEvent> : IEventHandler<TEvent>
{
    private IEnumerable<IEventHandler<TEvent>> handlers;

    public MultipleDispatchEventHandler(Container container)
    {
        var handlerType = typeof(IEventHandler<TEvent>);

        var registrations = (
            from r in container.GetCurrentRegistrations()
            where handlerType.IsAssignableFrom(r.ServiceType)
            select r)
            .ToArray();

        this.handlers =
            from r in registrations
            select (IEventHandler<TEvent>)r.GetInstance();
    }

    void IEventHandler<TEvent>.Handle(TEvent e) { ... }
}
{{< / highlight >}}

However, there is a problem with this, that you will notice when trying to resolve this composite. Registering open generic types (using the RegisterOpenGeneric or RegisterSingleOpenGeneric) works by hooking onto the container's ResolveUnregisteredType event, which will only get called for unregistered types. We did however already register an `IEventHandler<CustomerMovedEvent>` and an `IEventHandler<CustomerMovedAbroadEvent>`. Resolving those handlers will therefore result in the return of a CustomerMovedEventHandler and CustomerMovedAbroadEventHandler respectively. All other `IEventHandler<TEvent>` versions will result in the return of the MultipleDispatchEventHandler. Of course, this is not what we want.

We can fix this by registering the concrete types explicitly:

{{< highlight csharp >}}
container.Register<CustomerMovedEventHandler>();
container.Register<CustomerMovedAbroadEventHandler>();
{{< / highlight >}}

Or of course using batch registration:

{{< highlight csharp >}}
container.RegisterManyForOpenGeneric(typeof(IEventHandler<>),
    (service, impls) => container.Register(impls.Single()),
    AppDomain.CurrentDomain.GetAssemblies());
{{< / highlight >}}

The supplied registration callback in the RegisterManyForOpenGeneric method simply calls the non-generic Register method overload, and because there is only one implementation per service type, we can call impls.Single(). In case there were more implementations, we could simply do something like this:

{{< highlight csharp >}}
container.RegisterManyForOpenGeneric(typeof(IEventHandler<>),
    (_, impls) => impls.ToList().ForEach(i => container.Register(i)),
    AppDomain.CurrentDomain.GetAssemblies());
{{< / highlight >}}

Now we have to ensure that the MultipleDispatchEventHandler queries for these concrete types:

{{< highlight csharp >}}
public sealed class MultipleDispatchEventHandler<TEvent> : IEventHandler<TEvent>
{
    private IEnumerable<IEventHandler<TEvent>> handlers;

    public MultipleDispatchEventHandler(Container container)
    {
        var handlerType = typeof(IEventHandler<TEvent>);

        var registrations = (
            from r in container.GetCurrentRegistrations()
            let assignableInterfaces = (
                from intface in r.ServiceType.GetInterfaces()
                where handlerType.IsAssignableFrom(intface)
                select intface)
            where assignableInterfaces.Any()
            select r)
            .ToArray();

        this.handlers =
            from r in registrations
            select (IEventHandler<TEvent>)r.GetInstance();
    }

    void IEventHandler<TEvent>.Handle(TEvent e) { ... }
}
{{< / highlight >}}

Instead of querying the container for `IEventHandler<TEvent>` registrations, we query the container for service types that implement the `IEventHandler<TEvent>` interface.

With this we successfully finished this third scenario. However, in the name of science, let's take a look at a different approach, using unregistered type resolution. Just as we did with the second scenario, we can use unregistered type resolution. Take a look at the following extension method:

{{< highlight csharp >}}
public static void AllowToResolveVariantCollections(this Container container)
{
    container.ResolveUnregisteredType += (sender, e) =>
    {
        // Only handle IEnumerable<T>.
        if (!e.UnregisteredServiceType.IsGenericType || 
            e.UnregisteredServiceType.GetGenericTypeDefinition() != typeof(IEnumerable<>))
        {
            return;
        }

        Type serviceType = e.UnregisteredServiceType.GetGenericArguments()[0];

        if (!serviceType.IsGenericType)
        {
            return;
        }

        Type def = serviceType.GetGenericTypeDefinition();

        var registrations = (
            from r in container.GetCurrentRegistrations()
            where r.ServiceType.IsGenericType
            where r.ServiceType.GetGenericTypeDefinition() == def
            where serviceType.IsAssignableFrom(r.ServiceType)
            select r)
            .ToArray();
        
        if (registrations.Any())
        {
            var instances = registrations.Select(r => r.GetInstance());

            var castMethod = typeof(Enumerable).GetMethod("Cast")
                .MakeGenericMethod(serviceType);

            var castedInstances = castMethod.Invoke(null, new[] { instances });

            e.Register(() => castedInstances);
        }
    };
}
{{< / highlight >}}

This extension method looks much like the extension method we've seen in the second scenario, with a few differences. First of all, this extension method handles IEnumerable<T> types. Just like before it searches the container for assignable types, but if they are found, an IEnumerable<T> is constructed that will return all found services.

We can use this extension method as follows:

{{< highlight csharp >}}
container.AllowToResolveVariantCollections();
{{< / highlight >}}

With the current configuration we can now request an `IEnumerable<IEventHandler<TEvent>>` and get all assignable implementations:

{{< highlight csharp >}}
var handlers =
    container.GetAllInstances<IEventHandler<CustomerMovedAbroadEvent>>();
{{< / highlight >}}

This will result in a list that contains both the CustomerMovedEventHandler and CustomerMovedAbroadEventHandler.

As you can see there are many ways to skin a cat. If your scenario is (slightly) different from what we've discussed and you are experiencing some difficulties in finding a good solution, please drop me a note, or ask at Stack Overflow (don't forget to tag your question with 'simple-injector').

That's all for now.

Happy injecting!

## Comments

Comments haven't been migrated yet...