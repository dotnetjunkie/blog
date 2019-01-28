---
title:   "Dependency Injection in Attributes: don’t do it!"
date:    2014-05-14
author:  Steven van Deursen
tags:    [.NET general, ASP.NET, C#, Dependency Injection]
draft:   false
aliases:
    - /p/di-attributes
---

### A number of common frameworks have promoted the concept of using attributes as a way of implementing AOP. On the surface this seems perfectly acceptable but in reality the maintainability of these options degrades as you add behaviors by injecting dependencies into attributes. The point of this article is “don’t do it!” There are better ways and this article will describe one such alternative.

A long time has passed since the early formative stages of the .NET Framework and ideals such as the [original design patterns from the gang of four](https://en.wikipedia.org/wiki/Design_Patterns) have since achieved mainstream status. Approaches to development that include features such as dependency injection (DI) and [aspect-oriented programming](https://en.wikipedia.org/wiki/Aspect-oriented_programming) (AOP) are now the norm. It is fair to say that the original structure of the .NET framework was not conducive to applying AOP but some bright spark has struck upon the idea of blending attributes and services as a simple technique for adding AOP to our tool box.

Many frameworks, such as the ASP.NET frameworks for MVC and Web API now offer the option of applying AOP with attributes. For example, both frameworks contain an `ActionFilterAttribute` to be derived from for adding specific features such as routing and authentication to Controllers and/or Controller Actions. The ASP.NET stack leads us to the idea of mixing data and behavior by extending the `Attribute` class with the `OnActionExecuting` and `OnActionExecuted` methods.

This design has a limitation in that the responsibility for creating each `Attribute` instance is owned by the CLR and the creation of an attribute instance cannot be intercepted. It is the CLR that creates each attribute instance and as such we cannot rely on our DI container of choice to “automagically do its stuff” and give us the instance with all of its required dependencies injected through [constructor injection](https://martinfowler.com/articles/injection.html#FormsOfDependencyInjection). Instead we are forced to hook into the framework at runtime, sometime after the instance has been created and then attempt to add on the required services.

To introduce this post construction/pre activation hook, the ASP.NET frameworks provide an abstraction known as the [IFilterProvider](https://msdn.microsoft.com/en-us/library/system.web.mvc.ifilterprovider(v=vs.118).aspx). The `IFilterProvider` allows us to alter each attribute instance after it has been created by the runtime and before it is used by the rest of the ASP.NET framework.

By registering a custom `IFilterProvider` and relying on property injection for the dependencies, we are able to inject dependencies into attributes, but this process is fragile and presents us with a problem. A DI container composes service components, but attributes are data packages and there can be many variations of the same attribute (attributes will invariably have multiple optional constructor dependencies). In addition to this fact the container will simply not know how to create attributes because it is not a task it is permitted to do. It is this limitation that prevents us from [verifying the correctness](https://simpleinjector.org/howto#verify-configuration) of the entire container’s configuration. Verifying the correctness of the configuration is important as we do not wish to click through the entire application (i.e. regression test) each and every time the DI configuration changed (which will be constantly for a medium to large code base). We desire the ability to verify the correctness of the configuration during application startup and/or within an integration test or two.

Another problem with property injection when working with MVC or Web API is that these frameworks [cache attributes](https://stackoverflow.com/questions/27646196/asp-net-web-api-caches-action-filter-attributes-across-requests), making them singletons. This makes it very easy to accidentally create [captive dependencies](https://blog.ploeh.dk/2014/06/02/captive-dependency/) which can lead to all sorts of concurrency issues.

But, more importantly than anything mentioned up to this point - mixing attribute metadata with service behavior means it is impossible to apply cross-cutting concerns to the behavior. The concept of applying AOP to AOP may sound extreme but it is only a matter of time before you reach the point of having enough logic before and/or after the execution of an action that you want to apply some new cross-cutting concern to that logic. Consider, for example, the simple requirement of profiling the time it takes to execute a piece of logic. You can hack that extra feature into your filter attribute but it is ugly and it violates both [DRY](https://en.wikipedia.org/wiki/Don't_repeat_yourself) and the [Single Responsibility Principle](https://en.wikipedia.org/wiki/Single_responsibility_principle).

Fundamentally there is nothing wrong with the concept of applying AOP using attributes but if take a step back and consider the original idea of the humble attribute as “declarative tags […] to specify additional information […] that can be retrieved at run time through reflection” ([see](https://msdn.microsoft.com/en-us/library/aa288059(v=vs.71).aspx)). Attributes hold fixed, and specific, metadata relevant to their location within code. An attribute can realistically be seen as a sort of static [Parameter Object](https://refactoring.com/catalog/introduceParameterObject.html).

It's only as you consider the details of what it means to mix attributes with behavior in the conventional way, i.e. by injecting the behavior into the attribute, that you start to see how this goes against the principle of keeping the necessary boundaries between these two object types (data/behavior). The blending of data and behavior is a bad thing and the solution to this that follows is to explicitly separate the data from the behavior. You may have seen this done before [here](/steven/p/commands) and [here](/steven/p/queries) and the examples that follow, despite being specific to the ASP.NET Web API, should clearly demonstrate an attribute without behavior and its accompanying service:

{{< highlight csharp >}}
public interface IActionFilter<TAttribute> where TAttribute : Attribute
{
     void OnActionExecuting(TAttribute attribute, HttpActionContext context);
}

public class MinimumAgeAttribute : Attribute
{
    public readonly int MinimumAge;

    public MinimumAgeAttribute(int minimumAge)
    {
        this.MinimumAge = minimumAge;
    }
}

public class MinimumAgeActionFilter
    : IActionFilter<MinimumAgeAttribute>
{
    private readonly IRepository repository;

    public MinimumAgeActionFilter(IRepository repository)
    {
        this.repository = repository;
    }

    public void OnActionExecuting(
        MinimumAgeAttribute attribute, 
        HttpActionContext context)
    {
        Debug.WriteLine(
            "OnActionExecuting " + attribute.MinimumAge);
    }
}
{{< / highlight >}}

The example shows an attribute that allows restricting access to parts of the API for users of a particular age. Do note that this class does not inherit from any Web API specific attribute, in other words, the attribute is truly a humble behaviorless data container. All the related logic has been removed from the attribute and can now be found in the accompanying `MinimumAgeActionFilter` service (that implements our customly defined `IActionFilter<TAttribute>` interface). The attribute is now a *Parameter Object*.

Assuming that you have read any of my earlier blog posts you should now be noticing the common theme and that is that we have a message (`MinimumAgeAttribute`) a.k.a the attribute, which is a mere data container, and we have a handler (`MinimumAgeActionFilter`) which can process the message. In addition the handler is an implementation of the generic `IActionFilter<TAttribute>` interface.

As always, this design gives us a lot. The `MinimumAgeActionFilter` is a normal service and as such it can benefit from plain old constructor injected dependencies and can be registered with our DI container. The service has dependencies and the container will automatically resolve them for us and should throw an exception if any of the dependencies cannot be wired. As the container should be aware of all services it should also be able to verify whether all registrations can be successfully resolved, either during application start-up or with an integration test. This upfront knowledge of all dependencies enables the DI container to diagnose the configuration (as [you can do](https://simpleinjector.org/diagnostics) with [Simple Injector](https://simpleinjector.org)).

![Example of the Simple Injector Diagnostics Debugger Watch](/steven/images/diagnosticsdebuggerwatch.gif)

The first advantage of this design is that we can decorate all action filter services with one or more decorators, such as this one:


{{< highlight csharp >}}
public class ProfilingActionFilterDecorator<TAttribute>
    : IActionFilter<TAttribute>
    where TAttribute : Attribute
{
    private readonly IActionFilter<TAttribute> decoratee;
    private readonly ILogger logger;

    public ProfilingActionFilterDecorator(
        IActionFilter<TAttribute> decoratee, ILogger logger)
    {
        this.decoratee = decoratee;
        this.logger = logger;
    }

    public void OnActionExecuting(
        TAttribute attribute, HttpActionContext context)
    {
        this.logger.Info("Decorated OnActionExecuting.");
        this.decoratee.OnActionExecuting(attribute, context);
    }
}
{{< / highlight >}}

There are a lot of differences between the popular DI containers in their support for decorators. So your mileage might vary, but with Simple Injector all the IActionFilter<TAttribute> implementations can be registered in a single call:

{{< highlight csharp >}}
container.Collection.Register(
    typeof(IActionFilter<>), 
    typeof(IActionFilter<>).Assembly);
{{< / highlight >}}

And the decorator can simply be applied as follows:

{{< highlight csharp >}}
container.RegisterDecorator(
    typeof(IActionFilter<>), 
    typeof(ProfilingActionFilterDecorator<>));
{{< / highlight >}}

To get this working though, we inevitably need some infrastructure. In the case of Web API we need to create our own global filter that will dispatch the decorated attributes to our `IActionFilter<TAttribute>` implementations.


{{< highlight csharp >}}
public sealed class ActionFilterDispatcher : IActionFilter
{
    private readonly Func<Type, IEnumerable> container;

    public ActionFilterDispatcher(Func<Type, IEnumerable> container)
    {
        this.container = container;
    }

    public Task<HttpResponseMessage> ExecuteActionFilterAsync(
        HttpActionContext context,
        CancellationToken cancellationToken,
        Func<Task<HttpResponseMessage>> continuation)
    {
        var descriptor = context.ActionDescriptor;
        var attributes = descriptor.ControllerDescriptor
            .GetCustomAttributes<Attribute>(true)
            .Concat(descriptor.GetCustomAttributes<Attribute>(true));

        foreach (var attribute in attributes)
        {
            Type filterType = typeof(IActionFilter<>)
                .MakeGenericType(attribute.GetType());
            
            var filters = this.container.Invoke(filterType);

            foreach (dynamic actionFilter in filters)
            {
                actionFilter.OnActionExecuting((dynamic)attribute, context);
            }
        }

        return continuation();
    }

    public bool AllowMultiple => true;
}
{{< / highlight >}}

The `ActionFilterDispatcher` takes a Func delegate that allows resolving collections of types. During the call to `ExecuteActionFilterAsync`, the method will request all applicable attributes and will request the container for all `IActionFilter<TAttribute>` implemenations per attribute.

The following code will register our action filter components and the new action filter dispatcher in Web API:

{{< highlight csharp >}}
GlobalConfiguration.Configuration.Filters.Add(
    new ActionFilterDispatcher(container.GetAllInstances));

container.Collection.Register(
    typeof(IActionFilter<>), 
    typeof(IActionFilter<>).Assembly);
{{< / highlight >}}

## Wiring ASP.NET MVC

The code for MVC will be very similar. The interface will look as follows:

{{< highlight csharp >}}
public interface IActionFilter<TAttribute> where TAttribute : Attribute
{
    void OnActionExecuting(
        TAttribute attribute, ActionExecutingContext context);
}
{{< / highlight >}}

Here is MVC's `ActionFilterDispatcher`:

{{< highlight csharp >}}
using System;
using System.Collections;
using System.Linq;
using System.Web.Mvc;

public class ActionFilterDispatcher : IActionFilter
{
    private readonly Func<Type, IEnumerable> container;

    public ActionFilterDispatcher(Func<Type, IEnumerable> container)
    {
        this.container = container;
    }

    public void OnActionExecuting(ActionExecutingContext context)
    {
        var descriptor = context.ActionDescriptor;
        var attributes = descriptor.ControllerDescriptor
            .GetCustomAttributes(true)
            .Concat(descriptor.GetCustomAttributes(true))
            .Cast<Attribute>();

        foreach (var attribute in attributes)
        {
            Type filterType = typeof(IActionFilter<>)
                .MakeGenericType(attribute.GetType());
                
            var filters = this.container.Invoke(filterType);

            foreach (dynamic actionFilter in filters)
            {
                actionFilter.OnActionExecuting((dynamic)attribute, context);
            }
        }
    }

    public void OnActionExecuted(
        ActionExecutedContext filterContext) { }
}
{{< / highlight >}}

The following code allows our `ActionFilterDispatcher` for MVC to be added to MVC's pipeline:

{{< highlight csharp >}}
GlobalFilters.Filters.Add(
    new ActionFilterDispatcher(container.GetAllInstances));
{{< / highlight >}}

## ASP.NET Core MVC

The code for ASP.NET Core will be very similar, but since ASP.NET Core is a completely new framework, things look (again) a bit different. The interface will look as follows:

{{< highlight csharp >}}
public interface IActionFilter<TAttribute> where TAttribute : Attribute
{
    void OnActionExecuting(
        TAttribute attribute, ActionExecutingContext context);
}
{{< / highlight >}}

Here is ASP.NET Core's `ActionFilterDispatcher`:

{{< highlight csharp >}}
using System;
using System.Collections.Generic;
using System.Linq;
using System.Collections;
using System.Reflection;
using Microsoft.AspNet.Mvc.Filters;
using Microsoft.AspNet.Mvc.Controllers;

public sealed class ActionFilterDispatcher : IActionFilter
{
    private readonly Func<Type, IEnumerable> container;

    public ActionFilterDispatcher(Func<Type, IEnumerable> container)
    {
        this.container = container;
    }

    public void OnActionExecuting(ActionExecutingContext context)
    {
        IEnumerable<object> attributes =
            context.Controller.GetType().GetTypeInfo()
                .GetCustomAttributes(true);

        var descriptor = context.ActionDescriptor
            as ControllerActionDescriptor;

        if (descriptor != null)
        {
            attributes = attributes.Concat(
                descriptor.MethodInfo.GetCustomAttributes(true));
        }

        foreach (var attribute in attributes)
        {
            Type filterType = typeof(IActionFilter<>)
                .MakeGenericType(attribute.GetType());
            var filters = this.container.Invoke(filterType);

            foreach (dynamic actionFilter in filters)
            {
                actionFilter.OnActionExecuting((dynamic)attribute, context);
            }
        }
    }
    
    public void OnActionExecuted(ActionExecutedContext context) { }
}
{{< / highlight >}}

The following code allows our `ActionFilterDispatcher` for ASP.NET Core to be added to ASP.NET's pipeline:

{{< highlight csharp >}}
public void ConfigureServices(IServiceCollection services)
{
    // Add MVC services to the services container.
    services.AddMvc().Configure<MvcOptions>(options =>
    {
        options.Filters.Add(new ActionFilterDispatcher(
			container.GetAllInstances));
    });

    ...
}
{{< / highlight >}}

## Conclusion

It is unfortunate that the ASP.NET frameworks lead us to mix data and behavior through the decision to promote applying AOP techniques with attributes. We can still, however, get our design where we want it to be using abstractions such as those described in this post: a design where data and behavior are separated; attributes are just plain old messages; behaviors can easily be registered and decorated; and, most importantly of all, we have a DI Container configuration that can be completely diagnosed and verified before we deploy to production.

As always, happy injecting!

## Comments

---
#### Adriano - 27 November 15 
Hi,

Great post, thanks for that :) I would like to try do this at WCF and I have problem with Dispatcher. I don't know how to call BeforeCall from IParameterInspector with declaration container. Did you consider this problem with injection attributes at WCF? Thanks for help :)

---
#### Steven - 27 November 15 
Hi Adriano,

With WCF the problem becomes much simpler IMO, because what I usually do is let my WCF service be this tiny little maintenance free wrapper around my business layer (see [this](/steven/p/maintainable-wcf)). This means that attributes are defined on the message or handler level, and not as part of WCF service classes. This completely removes the problem.

---
#### Daniel - 04 December 15

> with Simple Injector all the `IActionFilter<T>` implementations can be registered in a single call:
>
``` c#
container.Register(typeof(IActionFilter<>), typeof(IActionFilter<>).Assembly);
```

However, later the action filter is registered using `container.RegisterCollection`. Why?

I was having issues with MVC using the first option, when I changed it to use `RegisterCollection` it worked :)

Since this attribute is just a basic DTO, is it still possible to somehow use it as a global filter?

---
#### Dave - 17 January 16
Is there a way to wire this up without a 3rd party injection framework in MVC 6 RC1: I can't figure out how to get this to work: new ActionFilterDispatcher(container.GetAllInstances) is container a part of another dependency framework and not something I can use by default in mvc 6? Thanks!

---
#### Steven - 15 April 16
Daniel,

Thank you for your comment. The use of `container.Register` is a bit confusing, because the use of `container.RegisterCollection` is required, because filters are resolved using `GetAllInstances`. Simple Injector makes an explicit difference between one-to-one mappings and registration of collections. I updated the article accordingly.

> Since this attribute is just a basic DTO, is it still possible to somehow use it as a global filter?

You can absolutely register a filter as global filter. As a matter of fact, the `ActionFilterDispatcher` is registered as global filter. You can also create your individual filters as MVC specific implementations that check whether a certain attribute is applied and handle the request accordingly. This does however couple the action filter implementations with MVC and requires you to let every filter have implement the check on attributes itself.

---
#### Steven - 15 April 16
Dave,

You can configure this by storing the `IServiceProvider` in a private field inside the `Configure` method and use this instance and register the `ActionFilterDispatcher` in the `ConfigureServices` that resolves from the stored `IServiceProvider`. Here's an example:

``` c#
private IServiceProvider serviceProvider;

private IEnumerable GetActionFilters(Type type) => this.serviceProvider.GetServices(type);

public void ConfigureServices(IServiceCollection services) {
    services.AddMvc().AddMvcOptions(options =>
    {
        options.Filters.Add(new ActionFilterDispatcher(this.GetActionFilters));
    });
    // more here
}

public void Configure(IApplicationBuilder app, IHostingEnvironment env, ILoggerFactory loggerFactory) {
    this.serviceProvider = app.ApplicationServices;
    // more here
}
```

---
#### Joseph Vaughan - 16 June 16
Hi Steven

Great article again, thank you. I feel like this is too restrictive to the `IActionFilter` interface however. What should I do if I wish to implement attributes for Authorization, Authentication, etc. in order to keep the execution order correct?

The solution I see currently is to define additional interfaces for `IAuthorizationFilter`, `IAuthenticationFilter`, etc. And then additionally implement `AuthorizationFilterDispatcher`, `AuthenticationFilterDispatcher`, and so on. Am I missing something that would make this simpler?

Thanks.

---
#### Steven - 26 June 16
Hi Joseph,

With this model, you don't inherit your attributes from MVC's `IAuthorizationFilter` or `IAuthenticationFilter`. You have framework-agnostic attributes and have one or multiple `IActionFilter<T>` implementations for each attribute.

When it comes to authorization however, I prefer to mark my [command messages](/steven/p/commands/) and [query message](/steven/p/queries/) with an attribute that either sets a permission or role. On top of that you can apply a decorator that checks authorization of the user (see for instance [this discussion](https://github.com/dotnetjunkie/solidservices/issues/4)). This keeps the authorization rules as close to the definition of your use cases as you possibly can from experience I can say that such model will prevent many security bugs in your application.

---
#### Daniel - 20 January 17
Hi Steven, let me clarify my previous question, I know the actual filter dispatcher is registered as a global filter, however, let's say that you want to attach the Minimum age attribute to all methods for all controllers, would it be possible to do this without having a base controller?

---
#### Steven - 25 February 17
Attaching behavior to *all* methods of all controllers basically means you want to have a 'global' filter. This is where you actually use the framework's `GlobalFilters` feature.
